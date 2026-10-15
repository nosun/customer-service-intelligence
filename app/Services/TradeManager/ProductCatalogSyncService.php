<?php

namespace App\Services\TradeManager;

use App\Models\CommerceProduct;
use App\Models\CommerceSite;
use Throwable;

class ProductCatalogSyncService
{
    public function __construct(private readonly ProductClient $client) {}

    /** @return array{status:string, product_id:?int, error:?string} */
    public function sync(CommerceSite $site, string $sn): array
    {
        $sn = trim($sn);
        try {
            $information = $this->client->product($site, $sn);
            $options = $this->client->options($site, $sn);
            $payload = ['information' => $information, 'options' => $options];
            $product = CommerceProduct::updateOrCreate(
                ['commerce_site_id' => $site->id, 'sn' => $sn],
                [
                    'name' => (string) data_get($information, 'product.name', ''),
                    'product_url' => data_get($information, 'product.product_url'),
                    'status' => data_get($information, 'product.status'),
                    'visible' => data_get($information, 'product.visible'),
                    'product' => $information['product'] ?? [],
                    'product_detail' => $information['product_detail'] ?? null,
                    'images' => $information['images'] ?? [],
                    'options' => $options,
                    'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                    'sync_status' => 'completed',
                    'sync_error' => null,
                    'synced_at' => now(),
                ],
            );

            return ['status' => 'completed', 'product_id' => $product->id, 'error' => null];
        } catch (Throwable $exception) {
            $existing = CommerceProduct::where('commerce_site_id', $site->id)->where('sn', $sn)->first();
            $existing?->update([
                'sync_status' => 'failed',
                'sync_error' => mb_substr($exception->getMessage(), 0, 5000),
                'synced_at' => now(),
            ]);

            return ['status' => 'failed', 'product_id' => $existing?->id, 'error' => $exception->getMessage()];
        }
    }
}
