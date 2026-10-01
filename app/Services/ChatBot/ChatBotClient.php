<?php

namespace App\Services\ChatBot;

use DateTimeImmutable;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChatBotClient
{
    private readonly string $accessToken;

    private readonly string $baseUrl;

    public function __construct(?string $accessToken = null, ?string $baseUrl = null)
    {
        $this->accessToken = trim($accessToken ?? (string) config('services.chatbot.access_token'));
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.chatbot.base_url'), '/');
    }

    /** @return list<array<string, mixed>> */
    public function listStories(): array
    {
        return $this->listResponse('/v2/stories', 'story list');
    }

    /** @return array<string, mixed> */
    public function getStory(string $storyId): array
    {
        return $this->objectResponse("/v2/stories/{$storyId}", 'story');
    }

    /** @return list<array<string, mixed>> */
    public function listChats(?int $after = null): array
    {
        $query = $after === null ? [] : ['after' => $after];

        return $this->listResponse('/v2/chats', 'chat list', $query);
    }

    /** @return Generator<int, list<array<string, mixed>>> */
    public function chatPages(int $limit): Generator
    {
        $limit = max(1, $limit);
        $count = 0;
        $after = null;

        while ($count < $limit) {
            $page = $this->listChats($after);
            if ($page === []) {
                break;
            }
            $items = array_slice($page, 0, $limit - $count);
            $count += count($items);
            yield $items;

            if ($count >= $limit || count($page) < 40) {
                break;
            }
            $createdAt = $page[array_key_last($page)]['createdAt'] ?? null;
            if (! is_string($createdAt) || $createdAt === '') {
                throw new RuntimeException('ChatBot chat page is missing the createdAt pagination cursor.');
            }
            $nextAfter = (int) (new DateTimeImmutable($createdAt))->format('Uv');
            if ($nextAfter === $after) {
                throw new RuntimeException('ChatBot chat pagination cursor did not advance.');
            }
            $after = $nextAfter;
        }
    }

    /** @return array<string, mixed> */
    public function getChat(string $chatId): array
    {
        return $this->objectResponse("/v2/chats/{$chatId}", 'chat');
    }

    /** @return list<array<string, mixed>> */
    public function listTrainingPhrases(): array
    {
        $this->ensureConfigured();
        $response = $this->request()->get('/training');
        $response->throw();
        $payload = $response->json();
        $items = is_array($payload) ? ($payload['items'] ?? null) : null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw new RuntimeException('ChatBot returned an invalid training phrase list.');
        }

        return $items;
    }

    /**
     * @param  array<string, int>  $query
     * @return list<array<string, mixed>>
     */
    private function listResponse(string $path, string $resource, array $query = []): array
    {
        $this->ensureConfigured();
        $response = $this->request()->get($path, $query);
        $response->throw();
        $payload = $response->json();
        if (! is_array($payload) || ! array_is_list($payload)) {
            throw new RuntimeException("ChatBot returned an invalid {$resource}.");
        }

        foreach ($payload as $item) {
            if (! is_array($item)) {
                throw new RuntimeException("ChatBot returned an invalid {$resource} item.");
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function objectResponse(string $path, string $resource): array
    {
        $this->ensureConfigured();
        $response = $this->request()->get($path);
        $response->throw();
        $payload = $response->json();
        if (! is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException("ChatBot returned an invalid {$resource}.");
        }

        return $payload;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->accessToken)
            ->acceptJson()
            ->withUserAgent('customer-service-intelligence/0.1')
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(3, 500, throw: false);
    }

    private function ensureConfigured(): void
    {
        if ($this->accessToken === '') {
            throw new RuntimeException(
                'ChatBot credentials are missing. Configure CHATBOT_DEVELOPER_ACCESS_TOKEN.',
            );
        }
    }
}
