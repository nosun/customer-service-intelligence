<?php

namespace Tests\Unit;

use App\Services\ProductQuestions\ProductQuestionDraftPlanGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductQuestionDraftPlanGuardTest extends TestCase
{
    #[DataProvider('intentCases')]
    public function test_it_recovers_high_value_intents_and_removes_known_misclassifications(
        string $question,
        array $modelIntents,
        array $expected,
    ): void {
        $actual = app(ProductQuestionDraftPlanGuard::class)->normalizeIntents($question, $modelIntents);

        foreach ($expected as $intent) {
            $this->assertContains($intent, $actual);
        }
    }

    public static function intentCases(): array
    {
        return [
            'built in bra' => ['Does it have a built-in bra?', [], ['ask_built_in_bra']],
            'closure' => ['Is the back a zipper or lace-up?', [], ['ask_closure']],
            'fit' => ['Does this style run small?', ['ask_size_recommendation'], ['ask_fit']],
            'plus size' => ['Is US 26W available?', ['ask_in_stock'], ['ask_plus_size']],
            'remove train' => ['Can you shorten the train?', ['request_custom_length'], ['request_remove_train']],
            'multi customization' => ['Can you make it red, add sleeves, and shorten it?', ['request_custom_color', 'request_custom_length'], ['request_custom_color', 'request_add_sleeves', 'request_custom_length']],
            'tracking' => ['My tracking has not updated for a week.', ['ask_order_status'], ['ask_tracking']],
            'cancellation policy' => ['Can I cancel after production starts?', ['ask_order_status'], ['ask_cancellation_policy']],
            'fabric swatch is one intent' => ['Can I order a fabric swatch?', ['ask_fabric'], ['ask_swatch']],
            'actual photo' => ['Can you show me an actual product photo?', ['ask_guarantee'], ['ask_product_photos']],
            'spam' => ['Visit my website for cheap traffic and SEO services.', [], ['spam_or_irrelevant']],
        ];
    }

    public function test_it_adds_a_clause_for_every_recovered_intent(): void
    {
        $guard = app(ProductQuestionDraftPlanGuard::class);
        $clauses = $guard->normalizeClauses(
            'Does it have pockets and can it arrive in France before June 1?',
            [['text' => 'Does it have pockets?', 'intent' => 'ask_pockets', 'status' => 'answerable']],
            ['ask_pockets', 'request_add_pockets', 'ask_delivery_time'],
        );

        $this->assertSame(
            ['ask_pockets', 'request_add_pockets', 'ask_delivery_time'],
            array_column($clauses, 'intent'),
        );
    }

    public function test_fabric_swatch_does_not_create_a_second_generic_fabric_intent(): void
    {
        $actual = app(ProductQuestionDraftPlanGuard::class)->normalizeIntents(
            'Can I order a fabric swatch?',
            ['ask_fabric'],
        );

        $this->assertSame(['ask_swatch'], $actual);
    }

    public function test_it_requires_human_review_for_unsafe_intents_and_missing_live_facts(): void
    {
        $reasons = app(ProductQuestionDraftPlanGuard::class)->blockingReasons(
            ['request_add_sleeves', 'ask_shipping_options'],
            ['product' => ['stock_specs' => [['size' => '8', 'status' => 'in_stock']]]],
            ['country_code_for_shipping'],
        );

        $this->assertContains('intent_requires_human_review:request_add_sleeves', $reasons);
        $this->assertContains('missing_required_live_fact', $reasons);
    }

    public function test_verified_policy_allows_policy_dependent_intent_to_continue(): void
    {
        $reasons = app(ProductQuestionDraftPlanGuard::class)->blockingReasons(
            ['ask_swatch'],
            [],
            [],
            ['ask_swatch'],
        );

        $this->assertNotContains('intent_requires_human_review:ask_swatch', $reasons);
        $this->assertContains(
            'intent_requires_human_review:ask_cancellation_policy',
            app(ProductQuestionDraftPlanGuard::class)->blockingReasons(['ask_cancellation_policy'], [], []),
        );
    }
}
