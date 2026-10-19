<?php

namespace App\Services\ProductQuestions;

use App\Models\ProductQuestionAnalysis;

class ProductQuestionDraftPlanGuard
{
    /** @return list<string> */
    public function supportedIntents(): array
    {
        return array_values(array_diff(ProductQuestionAnalysis::INTENTS, ['unclear_or_multi_intent']));
    }

    /** @param list<string> $intents
     * @return list<string>
     */
    public function normalizeIntents(string $question, array $intents): array
    {
        if (preg_match('/\b(?:see|view|show)\b.{0,80}\b(?:different|other|available)\s+colou?rs?\b/i', $question) === 1
            && preg_match('/\b(?:photos?|pictures?|images?|actual|real|historical|older|previous)\b/i', $question) !== 1
        ) {
            $intents = array_values(array_diff($intents, ['ask_color_photos', 'ask_product_photos']));
            $intents[] = 'ask_available_colors';
        }
        foreach ($this->intentRules() as $rule) {
            if (preg_match($rule['pattern'], $question) !== 1) {
                continue;
            }
            $intents = array_values(array_diff($intents, $rule['remove'] ?? []));
            $intents = [...$intents, ...$rule['add']];
        }

        return array_values(array_unique(array_filter(
            $intents,
            fn (mixed $intent): bool => is_string($intent) && in_array($intent, $this->supportedIntents(), true),
        )));
    }

    /** @param list<array<string,mixed>> $clauses
     * @param  list<string>  $intents
     * @return list<array{text:string,intent:string,status:string}>
     */
    public function normalizeClauses(string $question, array $clauses, array $intents): array
    {
        $normalized = [];
        foreach ($clauses as $clause) {
            if (! is_array($clause) || ! in_array($clause['intent'] ?? null, $intents, true)) {
                continue;
            }
            $normalized[] = [
                'text' => mb_substr(trim((string) ($clause['text'] ?? $question)), 0, 500),
                'intent' => (string) $clause['intent'],
                'status' => in_array($clause['status'] ?? null, ['answerable', 'needs_fact', 'must_block'], true)
                    ? (string) $clause['status']
                    : 'needs_fact',
            ];
        }
        $covered = array_column($normalized, 'intent');
        foreach (array_diff($intents, $covered) as $intent) {
            $normalized[] = ['text' => $question, 'intent' => $intent, 'status' => 'needs_fact'];
        }

        return $normalized;
    }

    /** @param list<string> $intents
     * @param  array<string,mixed>  $facts
     * @param  list<string>  $missing
     * @param  list<string>  $verifiedPolicyIntents
     * @return list<string>
     */
    public function blockingReasons(array $intents, array $facts, array $missing, array $verifiedPolicyIntents = []): array
    {
        $alwaysBlock = [
            'ask_product_photos', 'ask_color_photos', 'ask_fit', 'request_custom_color',
            'request_custom_length', 'request_add_sleeves', 'request_remove_sleeves',
            'request_change_neckline', 'request_change_back', 'request_remove_train',
            'request_add_train', 'request_mix_designs', 'request_other_customization',
            'ask_restock', 'ask_tracking', 'ask_discount', 'ask_guarantee',
            'ask_refund', 'ask_order_status', 'spam_or_irrelevant',
        ];
        $reasons = collect($intents)
            ->intersect($alwaysBlock)
            ->map(fn (string $intent): string => 'intent_requires_human_review:'.$intent)
            ->values()
            ->all();
        foreach (['ask_swatch', 'ask_cancellation_policy'] as $policyIntent) {
            if (in_array($policyIntent, $intents, true) && ! in_array($policyIntent, $verifiedPolicyIntents, true)) {
                $reasons[] = 'intent_requires_human_review:'.$policyIntent;
            }
        }

        if (in_array('ask_in_stock', $intents, true) && blank(data_get($facts, 'product.stock_specs'))) {
            $reasons[] = 'missing_current_stock_fact';
        }
        if (collect($missing)->contains(fn (string $fact): bool => in_array($fact, [
            'country_code_for_eta', 'country_code_for_shipping', 'product.dress_length',
        ], true))) {
            $reasons[] = 'missing_required_live_fact';
        }

        return array_values(array_unique($reasons));
    }

    /** @return list<array{pattern:string,add:list<string>,remove?:list<string>}> */
    private function intentRules(): array
    {
        return [
            ['pattern' => '/\bbuilt[- ]?in bra\b/i', 'add' => ['ask_built_in_bra']],
            ['pattern' => '/\b(?:zipper|lace[- ]?up|back closure)\b/i', 'add' => ['ask_closure']],
            ['pattern' => '/\b(?:red|green|blue|black|white|ivory|champagne|pink|purple|burgundy|navy|teal|gold|silver)\b.{0,25}\bavailable\b/i', 'add' => ['ask_available_colors'], 'remove' => ['request_custom_color']],
            ['pattern' => '/\b(?:fabric|material)\b/i', 'add' => ['ask_fabric'], 'remove' => ['ask_guarantee']],
            ['pattern' => '/\bfabric swatch\b|\bswatch\b/i', 'add' => ['ask_swatch'], 'remove' => ['ask_fabric']],
            ['pattern' => '/\bsize chart\b/i', 'add' => ['ask_size_chart']],
            ['pattern' => '/\b(?:run|runs)\s+(?:small|large)|\btrue to size\b/i', 'add' => ['ask_fit'], 'remove' => ['ask_size_recommendation']],
            ['pattern' => '/\b(?:US\s*)?\d{1,2}W\b/i', 'add' => ['ask_plus_size'], 'remove' => ['ask_in_stock', 'ask_size_recommendation']],
            ['pattern' => '/\b(?:shorten|remove)\b.{0,30}\btrain\b|\btrain\b.{0,30}\b(?:shorten|remove)\b/i', 'add' => ['request_remove_train'], 'remove' => ['request_custom_length']],
            ['pattern' => '/\b(?:add|added|adding)\b.{0,30}\bsleeves?\b|\bsleeves?\b.{0,30}\badded\b/i', 'add' => ['request_add_sleeves']],
            ['pattern' => '/\b(?:change|changed|make)\b.{0,35}\bneckline\b|\bneckline\b.{0,35}\b(?:change|V[- ]?neck)\b/i', 'add' => ['request_change_neckline']],
            ['pattern' => '/\b(?:combine|mix)\b.{0,50}\b(?:top|bodice)\b.{0,50}\b(?:skirt|dress)\b/i', 'add' => ['request_mix_designs']],
            ['pattern' => '/\bin stock\b/i', 'add' => ['ask_in_stock']],
            ['pattern' => '/\brestock(?:ed|ing)?\b/i', 'add' => ['ask_restock']],
            ['pattern' => '/\bdiscount\b|\bpromo(?:tion)?\b/i', 'add' => ['ask_discount']],
            ['pattern' => '/\btracking\b/i', 'add' => ['ask_tracking'], 'remove' => ['ask_order_status']],
            ['pattern' => '/\bguarantee\b.{0,30}\b(?:delivery|arriv)/i', 'add' => ['ask_delivery_time', 'ask_guarantee'], 'remove' => ['request_rush_order']],
            ['pattern' => '/\bcancel(?:lation)?\b.{0,50}\bproduction\b/i', 'add' => ['ask_cancellation_policy'], 'remove' => ['ask_order_status']],
            ['pattern' => '/\b(?:actual|real)\b.{0,30}\b(?:product )?photos?\b/i', 'add' => ['ask_product_photos'], 'remove' => ['ask_guarantee']],
            ['pattern' => '/\b(?:actual|real)\b.{0,20}\bcolou?r\b.{0,20}\bphotos?\b|\bcolou?r\b.{0,20}\bphotos?\b/i', 'add' => ['ask_color_photos'], 'remove' => ['ask_product_photos', 'ask_guarantee']],
            ['pattern' => '/\bproduct page\b.{0,30}\b(?:not|won.t|will not|cannot|can.t)\b.{0,20}\b(?:open|load)\b/i', 'add' => ['ask_how_to_order'], 'remove' => ['spam_or_irrelevant']],
            ['pattern' => '/\b(?:cheap traffic|SEO services?|visit my website|backlinks?)\b/i', 'add' => ['spam_or_irrelevant']],
        ];
    }
}
