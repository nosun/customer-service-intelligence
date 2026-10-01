<?php

namespace App\Services\Gmail;

use App\Models\Attachment;
use App\Models\GmailThread;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\SyncRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class GmailSyncService
{
    public function __construct(private readonly GmailMessageParser $parser) {}

    /** @return array{mailbox: string, source: string, query: string, threads_seen: int, messages_seen: int} */
    public function sync(
        string $mailboxEmail,
        string $query = 'in:anywhere',
        int $pageLimit = 10,
        ?int $threadLimit = null,
        bool $resume = false,
        ?string $fixture = null,
        ?string $gwsConfigDir = null,
        ?callable $progress = null,
    ): array {
        $mailboxEmail = strtolower($mailboxEmail);
        $progress?->__invoke("准备同步邮箱 {$mailboxEmail}，查询条件：{$query}");
        $mailbox = Mailbox::firstOrCreate(['email' => $mailboxEmail], ['provider' => 'gmail']);
        $source = $fixture === null ? 'gws' : 'fixture';
        $run = SyncRun::create([
            'mailbox_id' => $mailbox->id,
            'source' => $source,
            'query' => $query,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $totals = ['threads' => 0, 'messages' => 0];

        try {
            foreach ($this->threads($mailbox, $query, $pageLimit, $threadLimit, $resume, $fixture, $gwsConfigDir, $progress) as $thread) {
                $result = $this->importThread($mailbox, $thread);
                $totals['threads'] += $result['threads'];
                $totals['messages'] += $result['messages'];
                $progress?->__invoke("已保存 {$totals['threads']} 个 Thread、{$totals['messages']} 封邮件");
            }

            $run->update([
                'status' => 'completed',
                'threads_seen' => $totals['threads'],
                'messages_seen' => $totals['messages'],
                'finished_at' => now(),
            ]);
            $progress?->__invoke('同步完成');
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'threads_seen' => $totals['threads'],
                'messages_seen' => $totals['messages'],
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }

        return [
            'mailbox' => $mailboxEmail,
            'source' => $source,
            'query' => $query,
            'threads_seen' => $totals['threads'],
            'messages_seen' => $totals['messages'],
        ];
    }

    /** @return array{threads: int, messages: int} */
    public function importThread(Mailbox $mailbox, array $thread): array
    {
        $gmailThreadId = (string) ($thread['id'] ?? '');
        if ($gmailThreadId === '') {
            throw new RuntimeException('Gmail thread is missing id.');
        }

        $messages = array_map(
            fn (array $message): array => $this->parser->parse($message, $mailbox->email),
            $thread['messages'] ?? [],
        );
        usort($messages, static fn (array $a, array $b): int => strcmp((string) $a['sent_at'], (string) $b['sent_at']));

        DB::transaction(function () use ($mailbox, $thread, $gmailThreadId, $messages): void {
            $gmailThread = GmailThread::updateOrCreate(
                ['mailbox_id' => $mailbox->id, 'gmail_thread_id' => $gmailThreadId],
                [
                    'history_id' => isset($thread['historyId']) ? (string) $thread['historyId'] : null,
                    'snippet' => isset($thread['snippet']) ? (string) $thread['snippet'] : null,
                    'first_message_at' => $messages[0]['sent_at'] ?? null,
                    'last_message_at' => $messages !== [] ? $messages[array_key_last($messages)]['sent_at'] : null,
                    'message_count' => count($messages),
                ],
            );

            foreach ($messages as $data) {
                $attachments = $data['attachments'];
                unset($data['attachments']);

                $message = Message::updateOrCreate(
                    ['mailbox_id' => $mailbox->id, 'gmail_message_id' => $data['gmail_message_id']],
                    ['thread_id' => $gmailThread->id, ...$data],
                );

                Attachment::where('message_id', $message->id)->delete();
                foreach ($attachments as $attachment) {
                    Attachment::create(['message_id' => $message->id, ...$attachment]);
                }
            }
        });

        return ['threads' => 1, 'messages' => count($messages)];
    }

    /** @return iterable<array<string, mixed>> */
    private function threads(
        Mailbox $mailbox,
        string $query,
        int $pageLimit,
        ?int $threadLimit,
        bool $resume,
        ?string $fixture,
        ?string $gwsConfigDir,
        ?callable $progress,
    ): iterable {
        if ($fixture !== null) {
            $progress?->__invoke("正在读取测试数据：{$fixture}");
            $contents = file_get_contents($fixture);
            if ($contents === false) {
                throw new RuntimeException("Unable to read fixture: {$fixture}");
            }

            $threads = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($threads)) {
                throw new RuntimeException('Fixture must contain a JSON array of Gmail threads.');
            }

            if ($threadLimit !== null) {
                $threads = array_slice($threads, 0, $threadLimit);
            }
            if ($resume) {
                $existing = GmailThread::where('mailbox_id', $mailbox->id)
                    ->pluck('gmail_thread_id')
                    ->flip();
                $before = count($threads);
                $threads = array_values(array_filter(
                    $threads,
                    fn (array $thread): bool => ! $existing->has((string) ($thread['id'] ?? '')),
                ));
                $progress?->__invoke('断点继续：已跳过 '.($before - count($threads)).' 个已保存 Thread');
            }
            $progress?->__invoke('找到 '.count($threads).' 个测试 Thread');
            yield from $threads;

            return;
        }

        $client = new GwsClient($gwsConfigDir, progress: $progress);
        $progress?->__invoke('正在从 Gmail 获取 Thread 列表');
        $threadIds = $client->listThreadIds($query, max(1, $pageLimit));
        if ($threadLimit !== null) {
            $threadIds = array_slice($threadIds, 0, $threadLimit);
        }
        if ($resume) {
            $existing = GmailThread::where('mailbox_id', $mailbox->id)
                ->whereIn('gmail_thread_id', $threadIds)
                ->pluck('gmail_thread_id')
                ->all();
            $before = count($threadIds);
            $threadIds = array_values(array_diff($threadIds, $existing));
            $progress?->__invoke('断点继续：已跳过 '.($before - count($threadIds)).' 个已保存 Thread');
        }

        $total = count($threadIds);
        $progress?->__invoke("找到 {$total} 个 Thread，开始逐个读取完整会话");
        foreach ($threadIds as $index => $threadId) {
            $current = $index + 1;
            $progress?->__invoke("正在获取 Thread {$current}/{$total}");
            yield $client->getThread($threadId);
        }
    }
}
