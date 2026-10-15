<?php

namespace App\Services\TradeManager;

use App\Models\CommerceSite;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProductClient
{
    /** @return array<string, mixed> */
    public function product(CommerceSite $site, string $sn): array
    {
        return $this->get($site, 'products/'.rawurlencode($sn));
    }

    /** @return array<string, mixed> */
    public function options(CommerceSite $site, string $sn): array
    {
        return $this->get($site, 'products/'.rawurlencode($sn).'/options');
    }

    /** @return array<string, mixed> */
    private function get(CommerceSite $site, string $path): array
    {
        $response = Http::baseUrl(rtrim($site->admin_api_url, '/'))
            ->acceptJson()
            ->asJson()
            ->withToken($site->api_token)
            ->withHeader('X-Request-Id', 'csi-'.Str::uuid())
            ->connectTimeout(10)
            ->timeout(30)
            ->retry([1000, 3000], throw: false)
            ->get($path);

        return $this->data($response);
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        $payload = $response->json();
        $requestId = is_array($payload) && is_string($payload['request_id'] ?? null) ? $payload['request_id'] : null;
        if (! $response->successful() || data_get($payload, 'success') !== true || ! is_array(data_get($payload, 'data'))) {
            $code = is_array($payload) && is_string($payload['code'] ?? null) ? $payload['code'] : 'HTTP_'.$response->status();
            $message = is_array($payload) && is_string($payload['message'] ?? null)
                ? $payload['message']
                : 'tradeManager Admin API returned HTTP '.$response->status().'.';
            throw new TradeManagerApiException($message, $response->status(), $code, $requestId);
        }

        return $payload['data'];
    }
}
