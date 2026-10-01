<?php

namespace App\Console\Commands;

use App\Services\ChatBot\ChatBotClient;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class FetchChatBotSample extends Command
{
    protected $signature = 'chatbot:sample
        {--chat-limit=20 : Number of recent chats to fetch in full, maximum 40}
        {--output=storage/app/private/chatbot/api-sample.json : Private JSON output path}';

    protected $description = 'Fetch a read-only ChatBot sample containing stories, chats and unmatched training phrases';

    public function handle(ChatBotClient $client): int
    {
        $chatLimit = (int) $this->option('chat-limit');
        if ($chatLimit < 1 || $chatLimit > 40) {
            $this->error('Chat limit must be between 1 and 40.');

            return self::INVALID;
        }

        try {
            $storySummaries = $client->listStories();
            $stories = [];
            foreach ($storySummaries as $story) {
                $storyId = (string) ($story['id'] ?? '');
                if ($storyId === '') {
                    throw new RuntimeException('ChatBot story list item is missing id.');
                }
                $stories[] = $client->getStory($storyId);
            }

            $chatSummaries = array_slice($client->listChats(), 0, $chatLimit);
            $chats = [];
            foreach ($chatSummaries as $chat) {
                $chatId = (string) ($chat['id'] ?? '');
                if ($chatId === '') {
                    throw new RuntimeException('ChatBot chat list item is missing id.');
                }
                $chats[] = $client->getChat($chatId);
            }

            $payload = [
                'captured_at' => now()->toIso8601String(),
                'stories' => $stories,
                'chats' => $chats,
                'training_phrases' => $client->listTrainingPhrases(),
            ];
            $path = $this->outputPath();
            $directory = dirname($path);
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException("Unable to create ChatBot sample directory: {$directory}");
            }
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($path, $json."\n") === false || ! chmod($path, 0600)) {
                throw new RuntimeException("Unable to save ChatBot sample: {$path}");
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('ChatBot read-only sample saved.');
        $this->line(json_encode([
            'path' => $path,
            'stories' => count($stories),
            'chats' => count($chats),
            'training_phrases' => count($payload['training_phrases']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function outputPath(): string
    {
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            throw new RuntimeException('ChatBot sample output path cannot be empty.');
        }

        return str_starts_with($output, '/') ? $output : base_path($output);
    }
}
