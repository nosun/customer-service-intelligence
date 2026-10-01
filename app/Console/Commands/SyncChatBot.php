<?php

namespace App\Console\Commands;

use App\Services\ChatBot\ChatBotClient;
use App\Services\ChatBot\ChatBotImportService;
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
        {--fixture= : Import a local ChatBot sample instead of calling the API}';

    protected $description = 'Synchronize ChatBot stories and chats into versioned local tables';

    public function handle(ChatBotClient $client, ChatBotImportService $importer): int
    {
        $chatLimit = (int) $this->option('chat-limit');
        if ($chatLimit < 1) {
            $this->error('Chat limit must be at least 1.');

            return self::INVALID;
        }

        try {
            $payload = $this->fixturePayload();
            if ($payload === null) {
                $stories = [];
                foreach ($client->listStories() as $story) {
                    $storyId = trim((string) ($story['id'] ?? ''));
                    if ($storyId === '') {
                        throw new RuntimeException('ChatBot story list item is missing id.');
                    }
                    $stories[] = $client->getStory($storyId);
                }
                $chats = [];
                foreach ($client->chatPages($chatLimit) as $page) {
                    foreach ($page as $chat) {
                        $chatId = trim((string) ($chat['id'] ?? ''));
                        if ($chatId === '') {
                            throw new RuntimeException('ChatBot chat list item is missing id.');
                        }
                        $chats[] = $client->getChat($chatId);
                    }
                }
            } else {
                $stories = $payload['stories'];
                $chats = array_slice($payload['chats'], 0, $chatLimit);
            }

            $accountId = trim((string) ($this->option('account') ?: config('services.chatbot.account_id')));
            $result = $importer->import($stories, $chats, $accountId, $this->siteKey());
            $this->writeStoryFiles($stories);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('ChatBot synchronization completed.');
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
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
