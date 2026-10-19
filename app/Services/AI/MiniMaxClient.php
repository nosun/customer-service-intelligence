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
        private readonly string $provider = 'minimax',
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
        $deadline = microtime(true) + $this->completionTimeout();
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('AI provider completion timed out.');
            }
            $response = $this->request($remaining)->post('/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt.($this->provider === 'omniroute' ? "\nReturn only a valid JSON object." : '')],
                    ['role' => 'user', 'content' => $userPrompt.($this->provider === 'omniroute' ? "\nReturn only a valid JSON object." : '')],
                ],
                'max_completion_tokens' => $this->maxCompletionTokens,
                ...($this->provider === 'minimax' ? ['temperature' => 0.1, 'thinking' => ['type' => 'disabled']] : ['response_format' => ['type' => 'json_object']]),
            ]);
            $response->throw();

            $content = $response->json('choices.0.message.content');
            try {
                if (! is_string($content) || trim($content) === '') {
                    throw new RuntimeException('AI provider response does not contain message content.');
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

        throw new RuntimeException('AI provider structured completion failed.');
    }

    public function completionTimeout(): int
    {
        return min(180, max(30, $this->timeout));
    }

    private function request(float $remaining): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->withUserAgent('customer-service-intelligence/0.1')
            ->connectTimeout(min(10, $remaining))
            ->timeout($remaining);
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
            throw new RuntimeException('AI provider returned invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('AI provider structured output must be a JSON object.');
        }

        return $decoded;
    }

    private function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            $provider = $this->provider === 'minimax' ? 'MiniMax' : 'AI provider';
            throw new RuntimeException(
                $provider.' is not configured. Check its API key, base URL and model.',
            );
        }
    }
}
