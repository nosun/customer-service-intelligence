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
        ?string $fixture = null,
        ?string $gwsConfigDir = null,
    ): array {
        $mailboxEmail = strtolower($mailboxEmail);
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
            foreach ($this->threads($query, $pageLimit, $fixture, $gwsConfigDir) as $thread) {
                $result = $this->importThread($mailbox, $thread);
                $totals['threads'] += $result['threads'];
                $totals['messages'] += $result['messages'];
            }

            $run->update([
                'status' => 'completed',
                'threads_seen' => $totals['threads'],
                'messages_seen' => $totals['messages'],
                'finished_at' => now(),
            ]);
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
    private function threads(string $query, int $pageLimit, ?string $fixture, ?string $gwsConfigDir): iterable
    {
        if ($fixture !== null) {
            $contents = file_get_contents($fixture);
            if ($contents === false) {
                throw new RuntimeException("Unable to read fixture: {$fixture}");
            }

            $threads = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($threads)) {
                throw new RuntimeException('Fixture must contain a JSON array of Gmail threads.');
            }

            yield from $threads;

            return;
        }

        $client = new GwsClient($gwsConfigDir);
        foreach ($client->listThreadIds($query, max(1, $pageLimit)) as $threadId) {
            yield $client->getThread($threadId);
        }
    }
}
