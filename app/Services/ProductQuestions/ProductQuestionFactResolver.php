<?php

namespace App\Services\ProductQuestions;

use App\Models\CommerceProduct;
use App\Models\ProductQuestion;
use App\Services\TradeManager\ProductCatalogSyncService;
use App\Services\TradeManager\ProductQuestionClient;
use Throwable;

class ProductQuestionFactResolver
{
    public function __construct(
        private readonly ProductQuestionClient $client,
        private readonly ProductQuestionFactContract $contract,
        private readonly ProductCatalogSyncService $productSync,
        private readonly DressLengthChart $dressLengths,
    ) {}

    /** @return array{facts:array<string,mixed>,sources:list<array<string,mixed>>,missing:list<string>,safety_flags:list<string>,recommended_visible:bool} */
    public function resolve(ProductQuestion $question, array $plan): array
    {
        $question->loadMissing('site');
        $site = $question->site;
        $intents = array_values(array_filter($plan['intents'] ?? [], 'is_string'));
        $groups = $this->contract->factGroupsForIntents($intents);
        $facts = [
            'site' => [
                'brand_name' => $site->brand_name,
                'public_base_url' => rtrim((string) $site->public_base_url, '/'),
                'customer_service_email' => $site->customer_service_email,
                'operates_online_only' => $site->operates_online_only,
                'live_chat_enabled' => $site->live_chat_enabled,
                'public_phone' => $site->public_phone,
                'try_on_policy' => $site->try_on_policy,
                'physical_store_info' => $site->physical_store_info,
            ],
            'question' => [
                'product_sn' => $question->sn,
                'country_code' => $plan['country_code'] ?? null,
                'event_date' => $plan['event_date'] ?? null,
                'requested_currency' => $plan['requested_currency'] ?? null,
                'requested_language' => $plan['requested_language'] ?? null,
            ],
        ];
        $sources = [[
            'fact_group' => 'site',
            'source' => 'commerce_sites',
            'captured_at' => now()->toIso8601String(),
        ]];
        $missing = [];
        $safetyFlags = [];
        $unmatchedRequests = (array) ($plan['unmatched_requests'] ?? []);
        foreach ($unmatchedRequests as $request) {
            if (is_string($request) && trim($request) !== '') {
                $missing[] = 'unsupported_request:'.mb_substr(trim($request), 0, 160);
            }
        }

        if (in_array('product', $groups, true)) {
            $product = CommerceProduct::query()
                ->where('commerce_site_id', $question->commerce_site_id)
                ->where('sn', $question->sn)
                ->where('sync_status', 'completed')
                ->first();
            if ($product === null && $question->sn !== '') {
                $result = $this->productSync->sync($site, $question->sn);
                $product = CommerceProduct::query()
                    ->where('commerce_site_id', $question->commerce_site_id)
                    ->where('sn', $question->sn)
                    ->where('sync_status', 'completed')
                    ->first();
                if ($result['status'] !== 'completed') {
                    $safetyFlags[] = 'product fact lookup failed: '.($result['error'] ?? 'unknown error');
                }
            }
            if ($product === null) {
                $facts['product'] = $question->product;
                $missing[] = 'current_product_snapshot';
            } else {
                $facts['product'] = [
                    'sn' => $question->sn,
                    'name' => data_get($product->product, 'name'),
                    'type' => data_get($product->product, 'type'),
                    'sub_type' => data_get($product->product, 'sub_type'),
                    'price_usd' => data_get($product->product, 'sell_price'),
                    'product_url' => $product->product_url,
                    'processing_days_min' => data_get($product->product, 'processing_days_min'),
                    'processing_days_max' => data_get($product->product, 'processing_days_max'),
                    'customizable' => data_get($product->product, 'customizable'),
                    'attributes' => data_get($product->product_detail, 'attributes', []),
                    'summary' => data_get($product->product, 'summary'),
                    'images' => $product->images,
                    'colors' => data_get($product->options, 'colors', []),
                    'sizes' => data_get($product->options, 'sizes', []),
                    'size_chart' => data_get($product->options, 'size_chart', []),
                    'stock_specs' => data_get($product->options, 'stock_specs', []),
                    'additional_services' => data_get($product->options, 'additional_services', []),
                    'editable_fields' => data_get($product->options, 'editable_fields', []),
                    'custom_size_input_available' => $this->customSizeInputAvailable($product),
                    'custom_size_entry_method' => $this->customSizeEntryMethod($product),
                    'order_notes_available' => data_get($product->options, 'order_notes_available'),
                    'synced_at' => $product->synced_at?->toIso8601String(),
                ];
                $length = $this->dressLengths->lookup(
                    data_get($facts, 'product.attributes.field_hemlinetrain'),
                    $this->requestedSize($question->question),
                );
                if ($length !== null) {
                    $facts['product']['dress_length'] = $length;
                    $sources[] = [
                        'fact_group' => 'product.dress_length',
                        'source' => 'resources/data/dress-hollow-to-hem.json',
                        'captured_at' => now()->toIso8601String(),
                    ];
                } elseif (in_array('ask_dress_length', $intents, true)) {
                    $missing[] = 'product.dress_length';
                }
                $sources[] = [
                    'fact_group' => 'product',
                    'source' => 'commerce_products',
                    'captured_at' => $product->synced_at?->toIso8601String(),
                ];
                if (in_array('request_add_pockets', $intents, true)
                    && blank(data_get($facts, 'product.attributes.field_silhouette'))
                ) {
                    $missing[] = 'product.attributes.field_silhouette';
                }
            }
        }

        $countryCode = is_string($plan['country_code'] ?? null) ? strtoupper($plan['country_code']) : null;
        if (in_array('eta', $groups, true)) {
            if ($question->sn === '' || $countryCode === null) {
                $missing[] = $question->sn === '' ? 'product_sn_for_eta' : 'country_code_for_eta';
            } else {
                $this->captureLiveFact('eta', fn () => $this->client->productEta($site, $question->sn, $countryCode), $facts, $sources, $missing, $safetyFlags);
            }
        }
        if (in_array('shipping', $groups, true)) {
            if ($countryCode === null) {
                $missing[] = 'country_code_for_shipping';
            } else {
                $this->captureLiveFact('shipping', fn () => $this->client->shippingOptions($site, $countryCode), $facts, $sources, $missing, $safetyFlags);
            }
        }
        if (in_array('localization', $groups, true)) {
            $this->captureLiveFact('localization', fn () => $this->client->localizationOptions($site), $facts, $sources, $missing, $safetyFlags);
        }
        if (in_array('order', $groups, true)) {
            $missing[] = 'private_order_lookup';
            $safetyFlags[] = '具体订单事实必须在私密渠道由人工核验；不会发送给 AI。';
        }

        return [
            'facts' => $facts,
            'sources' => $sources,
            'missing' => array_values(array_unique($missing)),
            'safety_flags' => array_values(array_unique($safetyFlags)),
            'recommended_visible' => ! $this->contract->requiresPrivateReply($intents)
                && $unmatchedRequests === []
                && ! (bool) ($plan['requires_private_reply'] ?? false),
        ];
    }

    private function captureLiveFact(
        string $group,
        callable $callback,
        array &$facts,
        array &$sources,
        array &$missing,
        array &$safetyFlags,
    ): void {
        try {
            $value = $callback();
            $facts[$group] = $value;
            $sources[] = [
                'fact_group' => $group,
                'source' => $this->contract->definitions()[$group]['source'],
                'captured_at' => now()->toIso8601String(),
                'response_hash' => hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ];
        } catch (Throwable $exception) {
            $missing[] = $group.'_api_result';
            $safetyFlags[] = $group.' fact lookup failed: '.$exception->getMessage();
        }
    }

    private function customSizeInputAvailable(CommerceProduct $product): ?bool
    {
        $configured = data_get($product->options, 'custom_size_available');
        if (is_bool($configured)) {
            return $configured;
        }

        $labels = [
            ...data_get($product->options, 'sizes', []),
            ...collect(data_get($product->options, 'additional_services', []))
                ->map(fn (mixed $service): string => is_array($service)
                    ? (string) ($service['name'] ?? $service['label'] ?? '')
                    : (string) $service)
                ->all(),
        ];
        if (collect($labels)->contains(fn (mixed $label): bool => str_contains(strtolower((string) $label), 'custom size'))) {
            return true;
        }

        if (data_get($product->product, 'type') === 'dress'
            && in_array(data_get($product->product, 'customizable'), [true, 1, '1'], true)
        ) {
            return true;
        }

        return in_array(data_get($product->product, 'customizable'), [false, 0, '0'], true) ? false : null;
    }

    private function customSizeEntryMethod(CommerceProduct $product): ?string
    {
        $configured = data_get($product->options, 'custom_size_entry_method');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->customSizeInputAvailable($product) === true ? 'size_selector' : null;
    }

    private function requestedSize(string $question): ?string
    {
        return preg_match('/\b(?:us\s*)?size\s*([0-9]{1,2}w?)\b/i', $question, $matches) === 1
            ? strtoupper($matches[1])
            : null;
    }
}
