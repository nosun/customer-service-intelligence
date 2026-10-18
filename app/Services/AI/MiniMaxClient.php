<?php

namespace App\Services\AI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

class MiniMaxClient
{
    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly string $model;

    private readonly int $timeout;

    private readonly int $maxCompletionTokens;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $model = null,
        ?int $timeout = null,
        ?int $maxCompletionTokens = null,
    ) {
        $this->apiKey = trim($apiKey ?? (string) config('services.minimax.api_key'));
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.minimax.base_url'), '/');
        $this->model = trim($model ?? (string) config('services.minimax.model'));
        $this->timeout = $timeout ?? (int) config('services.minimax.timeout', 180);
        $this->maxCompletionTokens = $maxCompletionTokens
            ?? (int) config('services.minimax.max_completion_tokens', 12000);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '' && $this->model !== '';
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * @return array{output: array<string, mixed>, usage: array<string, mixed>, request_id: ?string}
     */
    public function structuredCompletion(string $systemPrompt, string $userPrompt): array
    {
        $this->ensureConfigured();
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = $this->request()->post('/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.1,
                'max_completion_tokens' => $this->maxCompletionTokens,
                'thinking' => ['type' => 'disabled'],
            ]);
            $response->throw();

            $content = $response->json('choices.0.message.content');
            try {
                if (! is_string($content) || trim($content) === '') {
                    throw new RuntimeException('MiniMax response does not contain message content.');
                }

                return [
                    'output' => $this->decodeJson($content),
                    'usage' => is_array($response->json('usage')) ? $response->json('usage') : [],
                    'request_id' => is_string($response->json('id')) ? $response->json('id') : null,
                ];
            } catch (RuntimeException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('MiniMax structured completion failed.');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->withUserAgent('customer-service-intelligence/0.1')
            ->connectTimeout(10)
            ->timeout(max(30, $this->timeout))
            ->retry(2, 750, throw: false);
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $content): array
    {
        $content = preg_replace('/<think>.*?<\/think>/s', '', trim($content)) ?? trim($content);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $content, $matches) === 1) {
            $content = $matches[1];
        } elseif (($start = strpos($content, '{')) !== false && ($end = strrpos($content, '}')) !== false) {
            $content = substr($content, $start, $end - $start + 1);
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('MiniMax returned invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('MiniMax structured output must be a JSON object.');
        }

        return $decoded;
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'MiniMax is not configured. Set MINIMAX_API_KEY, MINIMAX_API_URL and MINIMAX_MODEL.',
            );
        }
    }
}
