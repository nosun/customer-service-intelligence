<?php

namespace Tests\Unit;

use App\Services\ProductQuestions\ProductQuestionDraftReplaySuite;
use Tests\TestCase;

class ProductQuestionDraftReplaySuiteTest extends TestCase
{
    public function test_replay_suite_has_unique_diverse_cases_and_customer_decision_contrasts(): void
    {
        $cases = app(ProductQuestionDraftReplaySuite::class)->cases();

        $this->assertCount(59, $cases);
        $this->assertCount(59, array_unique(array_column($cases, 'id')));
        $this->assertGreaterThanOrEqual(10, count(array_unique(array_column($cases, 'category'))));
        $this->assertContains(true, array_column($cases, 'must_block'));
        $this->assertContains(false, array_column($cases, 'must_block'));
        $this->assertContains('de', array_column($cases, 'language'));
        $this->assertSame('Replay Fixture Dress', data_get($cases[0], 'fixture.product.name'));
        $this->assertSame(['Mermaid/Trumpet'], data_get($cases[2], 'fixture.product.attributes.field_silhouette'));
        $qualityCases = collect($cases)->keyBy('id');
        $this->assertSame(['As Shown'], data_get($qualityCases['decision-colors'], 'fixture.product.colors'));
        $this->assertSame(['6'], array_map('strval', array_keys(data_get($qualityCases['decision-measurement-comparison'], 'fixture.product.size_chart'))));
    }

    public function test_replay_metrics_detect_missed_intent_and_required_block(): void
    {
        $metrics = app(ProductQuestionDraftReplaySuite::class)->evaluate([
            ['id' => 'pq-001', 'intents' => ['ask_pockets'], 'blocked' => false, 'duration_ms' => 100, 'cost' => 0.01],
            ['id' => 'pq-010', 'intents' => ['request_custom_color'], 'blocked' => false, 'duration_ms' => 200, 'cost' => 0.02],
        ]);

        $this->assertSame(59, $metrics['suite_size']);
        $this->assertSame(2, $metrics['evaluated']);
        $this->assertSame(round(2 / 59, 4), $metrics['prediction_coverage']);
        $this->assertSame(0.0, $metrics['quality_review_coverage']);
        $this->assertSame(0.5, $metrics['missed_intent_rate']);
        $this->assertSame(0.5, $metrics['missed_required_block_rate']);
        $this->assertSame(100.0, $metrics['p50_duration_ms']);
        $this->assertSame(200.0, $metrics['p95_duration_ms']);
    }

    public function test_replay_metrics_accept_documented_status_and_singular_error_fields(): void
    {
        $suite = app(ProductQuestionDraftReplaySuite::class);
        $predictions = collect($suite->cases())->map(fn (array $case): array => [
            'id' => $case['id'],
            'status' => $case['must_block'] ? 'blocked' : 'generated',
            'intents' => $case['expected_intents'],
            'fact_error' => $case['id'] === 'pq-001' ? 1 : 0,
            'policy_violation' => $case['id'] === 'pq-002' ? 1 : 0,
        ])->all();

        $metrics = $suite->evaluate($predictions);

        $this->assertSame(round(1 / 59, 4), $metrics['fact_error_rate']);
        $this->assertSame(round(1 / 59, 4), $metrics['policy_violation_rate']);
        $this->assertSame(0.0, $metrics['false_block_rate']);
        $this->assertSame(0.0, $metrics['missed_required_block_rate']);
        $this->assertSame(0.0, $metrics['product_identity_repetition_rate']);
        $this->assertSame(0.0, $metrics['unsupported_follow_up_action_rate']);
        $this->assertSame(1.0, $metrics['quality_review_coverage']);
    }

    public function test_decision_quality_is_counted_only_when_explicitly_reviewed(): void
    {
        $metrics = app(ProductQuestionDraftReplaySuite::class)->evaluate([
            ['id' => 'decision-sizing-returns', 'relevance_ok' => true, 'guidance_ok' => true, 'tone_ok' => false],
            ['id' => 'decision-colors', 'relevance_ok' => null, 'guidance_ok' => null, 'tone_ok' => null],
        ]);
        $this->assertSame(0.5, $metrics['decision_quality_review_coverage']);
        $this->assertSame(1.0, $metrics['relevance_pass_rate']);
        $this->assertSame(1.0, $metrics['guidance_pass_rate']);
        $this->assertSame(0.0, $metrics['natural_tone_pass_rate']);
    }

    public function test_replay_metrics_detect_product_identity_and_unsupported_follow_up_action(): void
    {
        $metrics = app(ProductQuestionDraftReplaySuite::class)->evaluate([[
            'id' => 'pq-001',
            'status' => 'generated',
            'intents' => ['ask_pockets', 'request_add_pockets'],
            'answer_en' => 'Replay Dress ZP_123 is unavailable. Our team will check and get back to you.',
            'facts' => ['product' => ['name' => 'Replay Dress', 'sn' => 'ZP_123']],
        ]]);

        $this->assertSame(1.0, $metrics['product_identity_repetition_rate']);
        $this->assertSame(1.0, $metrics['unsupported_follow_up_action_rate']);
    }
}
