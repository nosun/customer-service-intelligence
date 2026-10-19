<?php

namespace App\Services\AI;

use RuntimeException;

class DraftCompletionClient
{
    public function provider(): string
    {
        return (string) config('services.draft_ai.provider', 'minimax');
    }

    public function model(): string
    {
        return (string) config('services.'.$this->provider().'.model');
    }

    public function isConfigured(): bool
    {
        return $this->client()->isConfigured();
    }

    public function structuredCompletion(string $systemPrompt, string $userPrompt): array
    {
        return [...$this->client()->structuredCompletion($systemPrompt, $userPrompt), 'provider' => $this->provider(), 'model' => $this->model()];
    }

    public function executionTimeout(): int
    {
        return $this->client()->completionTimeout() + 20;
    }

    private function client(): MiniMaxClient
    {
        if ($this->provider() === 'minimax') {
            return app(MiniMaxClient::class);
        }
        if ($this->provider() !== 'omniroute') {
            throw new RuntimeException('Unsupported Draft AI provider.');
        }

        return new MiniMaxClient(
            apiKey: (string) config('services.omniroute.api_key'),
            baseUrl: (string) config('services.omniroute.base_url'),
            model: $this->model(),
            timeout: (int) config('services.omniroute.timeout', 180),
            maxCompletionTokens: (int) config('services.omniroute.max_completion_tokens', 12000),
            provider: 'omniroute',
        );
    }
}
