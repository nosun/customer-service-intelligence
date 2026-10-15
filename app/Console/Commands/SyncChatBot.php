<?php

namespace App\Console\Commands;

use App\Models\ChannelAccount;
use App\Models\ChannelSyncCursor;
use App\Services\ChatBot\ChatBotClient;
use App\Services\ChatBot\ChatBotImportService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

class SyncChatBot extends Command
{
    protected $signature = 'chatbot:sync
        {--account= : Stable local name for this ChatBot account}
        {--site-key= : Site associated with the ChatBot account}
        {--chat-limit=200 : Maximum number of recent chats to synchronize}
        {--all : Synchronize the complete chat archive, resuming an interrupted crawl}
        {--concurrency=4 : Maximum concurrent chat-detail requests, from 1 to 8}
        {--page-delay-ms=500 : Delay between archive pages during a full crawl}
        {--fixture= : Import a local ChatBot sample instead of calling the API}';

    protected $description = 'Synchronize ChatBot stories and chats into versioned local tables';

    public function handle(ChatBotClient $client, ChatBotImportService $importer): int
    {
        $chatLimit = (int) $this->option('chat-limit');
        if ($chatLimit < 1) {
            $this->error('Chat limit must be at least 1.');

            return self::INVALID;
        }
        $concurrency = (int) $this->option('concurrency');
        $pageDelayMs = (int) $this->option('page-delay-ms');
        if ($concurrency < 1 || $concurrency > 8 || $pageDelayMs < 0 || $pageDelayMs > 5000) {
            $this->error('Concurrency must be from 1 to 8 and page delay from 0 to 5000 milliseconds.');

            return self::INVALID;
        }

        $accountId = trim((string) ($this->option('account') ?: config('services.chatbot.account_id')));
        if ($accountId === '') {
            $this->error('ChatBot account ID is missing.');

            return self::FAILURE;
        }

        $channelAccount = null;
        try {
            $payload = $this->fixturePayload();
            if ($payload !== null) {
                $result = $importer->import(
                    $payload['stories'],
                    array_slice($payload['chats'], 0, $chatLimit),
                    $accountId,
                    $this->siteKey(),
                );
                $this->writeStoryFiles($payload['stories']);
                $this->writeResult($result, 1, false, null);

                return self::SUCCESS;
            }

            $stories = $this->stories($client);
            $result = $importer->import($stories, [], $accountId, $this->siteKey());
            $this->writeStoryFiles($stories);
            $channelAccount = ChannelAccount::findOrFail($result['channel_account_id']);

            $fullCrawl = (bool) $this->option('all');
            $cursor = $fullCrawl ? $this->historicalCursor($channelAccount) : null;
            $initialAfter = $cursor === null ? null : (int) $cursor->cursor_value;
            $pages = 0;
            $limit = $fullCrawl ? PHP_INT_MAX : $chatLimit;

            foreach ($client->chatPages($limit, $initialAfter) as $page) {
                $chats = $this->chatDetails($client, $page, $concurrency);
                $pageResult = $importer->import([], $chats, $accountId, $this->siteKey());
                foreach (['stories', 'story_snapshots', 'chats', 'events'] as $key) {
                    $result[$key] += $pageResult[$key];
                }
                $pages++;

                if ($fullCrawl) {
                    $cursor = $this->saveHistoricalCursor($channelAccount, $page);
                    $this->line("Imported {$result['chats']} chats; checkpoint {$cursor->cursor_value}.");
                    if ($pageDelayMs > 0) {
                        usleep($pageDelayMs * 1000);
                    }
                }
            }

            if ($fullCrawl) {
                $cursor?->delete();
            }
        } catch (Throwable $exception) {
            $channelAccount?->update([
                'sync_status' => 'failed',
                'sync_error' => $exception->getMessage(),
            ]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->writeResult($result, $pages, $fullCrawl, $initialAfter);

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function stories(ChatBotClient $client): array
    {
        $stories = [];
        foreach ($client->listStories() as $story) {
            $storyId = trim((string) ($story['id'] ?? ''));
            if ($storyId === '') {
                throw new RuntimeException('ChatBot story list item is missing id.');
            }
            $stories[] = $client->getStory($storyId);
        }

        return $stories;
    }

    /**
     * @param  list<array<string, mixed>>  $page
     * @return list<array<string, mixed>>
     */
    private function chatDetails(ChatBotClient $client, array $page, int $concurrency): array
    {
        $chatIds = [];
        foreach ($page as $chat) {
            $chatId = trim((string) ($chat['id'] ?? ''));
            if ($chatId === '') {
                throw new RuntimeException('ChatBot chat list item is missing id.');
            }
            $chatIds[] = $chatId;
        }

        return $client->getChats($chatIds, $concurrency);
    }

    private function historicalCursor(ChannelAccount $account): ?ChannelSyncCursor
    {
        return ChannelSyncCursor::where([
            'channel_account_id' => $account->id,
            'source_resource' => 'chats',
            'silo' => 'archive',
            'status' => 'historical',
        ])->first();
    }

    /** @param list<array<string, mixed>> $page */
    private function saveHistoricalCursor(ChannelAccount $account, array $page): ChannelSyncCursor
    {
        $last = $page[array_key_last($page)];
        $createdAt = $last['createdAt'] ?? null;
        $externalId = trim((string) ($last['id'] ?? ''));
        if (! is_string($createdAt) || $createdAt === '' || $externalId === '') {
            throw new RuntimeException('ChatBot chat page is missing its pagination checkpoint.');
        }

        return ChannelSyncCursor::updateOrCreate(
            [
                'channel_account_id' => $account->id,
                'source_resource' => 'chats',
                'silo' => 'archive',
                'status' => 'historical',
            ],
            [
                'sort_by' => 'createdAt',
                'cursor_value' => (string) (int) (new DateTimeImmutable($createdAt))->format('Uv'),
                'cursor_external_id' => $externalId,
                'last_synced_at' => now(),
            ],
        );
    }

    /**
     * @param  array{channel_account_id: int, stories: int, story_snapshots: int, chats: int, events: int}  $result
     */
    private function writeResult(array $result, int $pages, bool $fullCrawl, ?int $resumedAfter): void
    {
        $this->info('ChatBot synchronization completed.');
        $this->line(json_encode([
            ...$result,
            'pages' => $pages,
            'full_crawl' => $fullCrawl,
            'resumed_after' => $resumedAfter,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @return array{stories: list<array<string, mixed>>, chats: list<array<string, mixed>>}|null */
    private function fixturePayload(): ?array
    {
        $fixture = $this->option('fixture');
        if (! is_string($fixture) || $fixture === '') {
            return null;
        }
        $path = str_starts_with($fixture, '/') ? $fixture : base_path($fixture);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read ChatBot fixture: {$path}");
        }
        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid ChatBot fixture: {$path}", previous: $exception);
        }
        $stories = is_array($payload) ? ($payload['stories'] ?? null) : null;
        $chats = is_array($payload) ? ($payload['chats'] ?? null) : null;
        if (! is_array($stories) || ! array_is_list($stories) || ! is_array($chats) || ! array_is_list($chats)) {
            throw new RuntimeException('ChatBot fixture must contain story and chat arrays.');
        }

        return ['stories' => $stories, 'chats' => $chats];
    }

    private function siteKey(): ?string
    {
        $siteKey = trim((string) $this->option('site-key'));

        return $siteKey === '' ? null : $siteKey;
    }

    /** @param list<array<string, mixed>> $stories */
    private function writeStoryFiles(array $stories): void
    {
        $directory = storage_path('app/private/chatbot/stories');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create ChatBot story directory: {$directory}");
        }
        foreach ($stories as $story) {
            $storyId = trim((string) ($story['id'] ?? ''));
            if ($storyId === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $storyId)) {
                throw new RuntimeException('ChatBot story has an invalid file id.');
            }
            $path = "{$directory}/{$storyId}.json";
            $json = json_encode($story, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($path, $json."\n") === false || ! chmod($path, 0600)) {
                throw new RuntimeException("Unable to save ChatBot story: {$path}");
            }
        }
    }
}
