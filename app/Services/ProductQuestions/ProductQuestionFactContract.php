<?php

namespace App\Services\ProductQuestions;

class ProductQuestionFactContract
{
    public const VERSION = 'product-question-facts-v2';

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return [
            'site' => [
                'source' => 'commerce_sites',
                'freshness' => 'configuration',
                'public' => true,
                'required' => ['brand_name', 'public_base_url'],
                'optional' => ['site_key', 'customer_service_email', 'operates_online_only', 'live_chat_enabled', 'public_phone', 'try_on_policy', 'physical_store_info'],
            ],
            'product' => ['source' => 'commerce_products', 'freshness' => 'latest_sync', 'public' => true, 'required' => ['sn', 'name']],
            'eta' => ['source' => 'tradeManager products/{sn}/eta', 'freshness' => 'live', 'public' => true, 'requires' => ['product_sn', 'country_code']],
            'shipping' => ['source' => 'tradeManager shipping/options', 'freshness' => 'live', 'public' => true, 'requires' => ['country_code']],
            'localization' => ['source' => 'tradeManager localization/options', 'freshness' => 'live', 'public' => true],
            'order' => ['source' => 'console-new orders/work-orders', 'freshness' => 'live', 'public' => false, 'requires_private_reply' => true],
        ];
    }

    /** @return list<string> */
    public function factGroupsForIntents(array $intents): array
    {
        $groups = ['site'];
        foreach ($intents as $intent) {
            $groups = [...$groups, ...match ($intent) {
                'ask_size_recommendation', 'ask_how_to_order', 'ask_production_time',
                'ask_guarantee', 'request_custom_size', 'request_custom_color', 'request_custom_length',
                'ask_product_details', 'ask_product_photos', 'compare_products', 'ask_weight',
                'ask_pockets', 'ask_lining', 'ask_dress_length', 'ask_built_in_bra', 'ask_closure', 'ask_train',
                'ask_available_colors', 'ask_color_match', 'ask_color_photos', 'ask_fabric',
                'ask_swatch', 'ask_size_chart', 'ask_fit', 'ask_plus_size', 'ask_in_stock',
                'ask_restock', 'request_add_pockets' => ['product'],
                'request_add_sleeves', 'request_remove_sleeves', 'request_change_neckline',
                'request_change_back', 'request_remove_train', 'request_add_train',
                'request_mix_designs', 'request_other_customization' => ['product'],
                'ask_price' => ['product', 'localization'],
                'ask_delivery_time' => ['eta'],
                'request_rush_order' => ['product'],
                'ask_shipping_options' => ['shipping'],
                'ask_payment' => ['localization'],
                'ask_order_status', 'ask_tracking', 'ask_refund' => ['order'],
                default => [],
            }];
        }

        return array_values(array_unique($groups));
    }

    public function requiresPrivateReply(array $intents): bool
    {
        return array_intersect(['ask_order_status', 'ask_tracking', 'ask_refund'], $intents) !== [];
    }
}
