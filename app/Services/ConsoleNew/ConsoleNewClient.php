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

    public function __construct(?string $baseUrl = null, ?string $token = null, ?int $timeout = null, private readonly string $connectionKey = 'seawave')
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.console_new.base_url'), '/');
        $this->token = $token ?? (string) config('services.console_new.token');
        $this->timeout = $timeout ?? (int) config('services.console_new.timeout', 15);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /** Safe to persist; credentials never appear in source metadata. */
    public function source(): array
    {
        return [
            'connection' => $this->connectionKey,
            'base_url' => $this->baseUrl,
            'fingerprint' => hash('sha256', $this->connectionKey.'|'.$this->baseUrl.'|'.$this->token),
        ];
    }

    /** Explicit allowlist for research reads; never accepts arbitrary URLs or mutations. */
    public function read(string $resource, array $query = [], ?string $identifier = null): array
    {
        $path = match ($resource) {
            'orders', 'work-orders', 'priority-orders', 'customer-contacts', 'order-modifications' => $resource,
            'order' => 'orders/'.rawurlencode($this->identifier($identifier)),
            'customer-contact' => 'customer-contacts/'.rawurlencode($this->identifier($identifier)),
            'customer-contact-histories' => 'customer-contacts/'.rawurlencode($this->identifier($identifier)).'/histories',
            'order-modification' => 'order-modifications/'.rawurlencode($this->identifier($identifier)),
            'order-modification-histories' => 'order-modifications/'.rawurlencode($this->identifier($identifier)).'/histories',
            default => throw new ConsoleNewApiException('Unsupported Console read resource.', errorCode: 'UNSUPPORTED_RESOURCE'),
        };
        $this->assertConfigured();
        $response = $this->request()->get($path, $query);
        $payload = $response->json();
        if (! $response->successful() || data_get($payload, 'success') !== true || ! is_array(data_get($payload, 'data'))) {
            throw $this->exception($response, $payload, data_get($payload, 'request_id'));
        }

        return ['data' => $payload['data'], 'request_id' => $payload['request_id'] ?? null,
            'response_hash' => hash('sha256', $response->body()), 'console' => $this->source()];
    }

    private function identifier(?string $identifier): string
    {
        if (blank($identifier)) {
            throw new ConsoleNewApiException('Console resource identifier is required.', errorCode: 'MISSING_IDENTIFIER');
        }

        return $identifier;
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, int>, request_id: ?string, response_hash: string} */
    public function findOrdersByEmail(string $email, int $pageSize = 10, int $page = 1): array
    {
        $this->assertConfigured();
        $response = $this->request()->get('orders', [
            'customer_email' => $email,
            'page' => $page,
            'page_size' => max(1, min($pageSize, 100)),
        ]);
        $payload = $response->json();
        $requestId = is_array($payload) && is_string($payload['request_id'] ?? null)
            ? $payload['request_id']
            : null;

        if (! $response->successful() || data_get($payload, 'success') !== true
            || ! is_array(data_get($payload, 'data.items'))
            || ! is_array(data_get($payload, 'data.pagination'))
        ) {
            throw $this->exception($response, $payload, $requestId);
        }

        return [
            'items' => data_get($payload, 'data.items'),
            'pagination' => data_get($payload, 'data.pagination'),
            'request_id' => $requestId,
            'response_hash' => hash('sha256', $response->body()),
        ];
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, int>, request_id: ?string, response_hash: string} */
    public function workOrders(string $orderNumber, int $pageSize = 100, int $page = 1): array
    {
        $this->assertConfigured();
        $response = $this->request()->get('work-orders', [
            'order_no' => $orderNumber,
            'page' => $page,
            'page_size' => max(1, min($pageSize, 100)),
        ]);
        $payload = $response->json();
        $requestId = is_array($payload) && is_string($payload['request_id'] ?? null)
            ? $payload['request_id']
            : null;

        if (! $response->successful() || data_get($payload, 'success') !== true
            || ! is_array(data_get($payload, 'data.items'))
            || ! is_array(data_get($payload, 'data.pagination'))
        ) {
            throw $this->exception($response, $payload, $requestId);
        }

        return [
            'items' => data_get($payload, 'data.items'),
            'pagination' => data_get($payload, 'data.pagination'),
            'request_id' => $requestId,
            'response_hash' => hash('sha256', $response->body()),
        ];
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

    /** Only cancellation and standard-size updates are exposed; no refund writes. */
    public function updateOrder(string $number, array $payload, ?int $itemId = null): array
    {
        if ($this->connectionKey !== 'seawave') {
            throw new ConsoleNewApiException('This Console connection is read-only.', errorCode: 'READ_ONLY_CONNECTION');
        }
        $this->assertConfigured();
        $changes = $payload['changes'] ?? [];
        if ($itemId === null ? $changes !== ['order_status' => -1] : (array_keys($changes) !== ['size'] || ! is_string($changes['size']))) {
            throw new ConsoleNewApiException('Unsupported Ticket order action.', errorCode: 'UNSUPPORTED_ACTION');
        }
        $path = 'orders/'.rawurlencode($number).($itemId === null ? '' : '/items/'.$itemId);
        $response = $this->request()->patch($path, [...$payload, 'send_email' => 0]);
        $data = $response->json();
        if (! $response->successful() || data_get($data, 'success') !== true || ! is_array(data_get($data, 'data'))) {
            throw $this->exception($response, $data, data_get($data, 'request_id'));
        }

        return ['data' => $data['data'], 'request_id' => $data['request_id'] ?? null];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->withToken($this->token)
            ->timeout($this->timeout)
            ->connectTimeout(min(5, $this->timeout));
    }

    /** Narrow adapter for first-round notes; no status, requirements, publish or release writes. */
    public function updateContactNote(int $id, array $payload): array
    {
        if ($this->connectionKey !== 'seawave') {
            throw new ConsoleNewApiException('This Console connection is read-only.', errorCode: 'READ_ONLY_CONNECTION');
        }
        $changes = $payload['changes'] ?? [];
        if ($id < 1 || array_keys($changes) !== ['internal_note', 'recorder']
            || ! is_string($changes['internal_note']) || mb_strlen($changes['internal_note']) > 20000
            || $changes['recorder'] !== 'nosun-agent' || ! is_int($payload['version'] ?? null) || $payload['version'] < 1) {
            throw new ConsoleNewApiException('Unsupported contact note update.', errorCode: 'UNSUPPORTED_ACTION');
        }
        $this->assertConfigured();
        $response = $this->request()->patch('customer-contacts/'.$id, $payload);
        $data = $response->json();
        if (! $response->successful() || data_get($data, 'success') !== true || ! is_array(data_get($data, 'data'))) {
            throw $this->exception($response, $data, data_get($data, 'request_id'));
        }

        return ['data' => $data['data'], 'request_id' => $data['request_id'] ?? null];
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new ConsoleNewApiException(
                'Console '.$this->connectionKey.' is not configured. Set its API URL and independent Token.',
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
