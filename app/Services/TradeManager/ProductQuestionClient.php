<?php

namespace App\Services\TradeManager;

use App\Models\CommerceSite;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProductQuestionClient
{
    /** @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>} */
    public function questions(CommerceSite $site, array $filters = []): array
    {
        $response = $this->readRequest($site)->get('product-questions', array_filter(
            $filters,
            fn (mixed $value): bool => $value !== null && $value !== '',
        ));

        return $this->data($response);
    }

    /** @return array<string, mixed> */
    public function question(CommerceSite $site, int $questionId): array
    {
        return $this->data($this->readRequest($site)->get('product-questions/'.$questionId))['question'];
    }

    /** @return array<string, mixed> */
    public function productEta(CommerceSite $site, string $sn, string $countryCode): array
    {
        return $this->data($this->readRequest($site)->get(
            'products/'.rawurlencode($sn).'/eta',
            ['country' => strtoupper($countryCode)],
        ));
    }

    /** @return array<string, mixed> */
    public function previewReply(CommerceSite $site, int $questionId, array $payload): array
    {
        return $this->data($this->request($site)->patch(
            'product-questions/'.$questionId.'/reply',
            [...$payload, 'preview' => true],
        ));
    }

    /** @return array<string, mixed> */
    public function confirmReply(
        CommerceSite $site,
        int $questionId,
        array $payload,
        string $confirmToken,
        string $idempotencyKey,
    ): array {
        return $this->data($this->request($site)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->patch('product-questions/'.$questionId.'/reply', [
                ...$payload,
                'confirm_token' => $confirmToken,
            ]));
    }

    private function request(CommerceSite $site): PendingRequest
    {
        return Http::baseUrl(rtrim($site->admin_api_url, '/'))
            ->acceptJson()
            ->asJson()
            ->withToken($site->api_token)
            ->withHeader('X-Request-Id', 'csi-'.Str::uuid())
            ->timeout(20)
            ->connectTimeout(10);
    }

    private function readRequest(CommerceSite $site): PendingRequest
    {
        return $this->request($site)->retry([1000, 3000], throw: false);
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        $payload = $response->json();
        $requestId = is_array($payload) && is_string($payload['request_id'] ?? null)
            ? $payload['request_id']
            : null;

        if (! $response->successful() || data_get($payload, 'success') !== true || ! is_array(data_get($payload, 'data'))) {
            $code = is_array($payload) && is_string($payload['code'] ?? null)
                ? $payload['code']
                : 'HTTP_'.$response->status();
            $message = is_array($payload) && is_string($payload['message'] ?? null)
                ? $payload['message']
                : 'tradeManager Admin API returned HTTP '.$response->status().'.';

            throw new TradeManagerApiException($message, $response->status(), $code, $requestId);
        }

        return $payload['data'];
    }
}
