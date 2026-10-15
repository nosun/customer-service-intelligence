<?php

namespace App\Services\ConsoleNew;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ConsoleNewClient
{
    private string $baseUrl;

    private string $token;

    private int $timeout;

    public function __construct(?string $baseUrl = null, ?string $token = null, ?int $timeout = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.console_new.base_url'), '/');
        $this->token = $token ?? (string) config('services.console_new.token');
        $this->timeout = $timeout ?? (int) config('services.console_new.timeout', 15);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /** @return array{status: 'found'|'not_found', data: ?array<string, mixed>, request_id: ?string, response_hash: string} */
    public function findOrder(string $orderNumber): array
    {
        $this->assertConfigured();
        $response = $this->request()->get('orders/'.rawurlencode($orderNumber));
        $payload = $response->json();
        $hash = hash('sha256', $response->body());
        $requestId = is_array($payload) && is_string($payload['request_id'] ?? null)
            ? $payload['request_id']
            : null;

        if ($response->status() === 404 && data_get($payload, 'errors.0.code') === 'ORDER_NOT_FOUND') {
            return [
                'status' => 'not_found',
                'data' => null,
                'request_id' => $requestId,
                'response_hash' => $hash,
            ];
        }

        if (! $response->successful() || data_get($payload, 'success') !== true || ! is_array(data_get($payload, 'data'))) {
            throw $this->exception($response, $payload, $requestId);
        }

        return [
            'status' => 'found',
            'data' => data_get($payload, 'data'),
            'request_id' => $requestId,
            'response_hash' => $hash,
        ];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->withToken($this->token)
            ->timeout($this->timeout)
            ->connectTimeout(min(5, $this->timeout));
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new ConsoleNewApiException(
                'console-new is not configured. Set CONSOLE_NEW_API_URL and CONSOLE_NEW_API_TOKEN.',
                errorCode: 'NOT_CONFIGURED',
            );
        }
    }

    private function exception(Response $response, mixed $payload, ?string $requestId): ConsoleNewApiException
    {
        $code = is_array($payload) && is_string(data_get($payload, 'errors.0.code'))
            ? data_get($payload, 'errors.0.code')
            : 'HTTP_'.$response->status();
        $message = is_array($payload) && is_string(data_get($payload, 'errors.0.message'))
            ? data_get($payload, 'errors.0.message')
            : 'console-new returned HTTP '.$response->status().'.';

        return new ConsoleNewApiException($message, $response->status(), $code, $requestId);
    }
}
