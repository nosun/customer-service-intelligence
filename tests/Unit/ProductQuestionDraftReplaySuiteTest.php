<?php

namespace Tests\Unit;

use App\Services\ProductQuestions\ProductQuestionDraftReplaySuite;
use Tests\TestCase;

class ProductQuestionDraftReplaySuiteTest extends TestCase
{
    public function test_replay_suite_has_fifty_unique_diverse_cases(): void
    {
        $cases = app(ProductQuestionDraftReplaySuite::class)->cases();

        $this->assertCount(50, $cases);
        $this->assertCount(50, array_unique(array_column($cases, 'id')));
        $this->assertGreaterThanOrEqual(10, count(array_unique(array_column($cases, 'category'))));
        $this->assertContains(true, array_column($cases, 'must_block'));
        $this->assertContains(false, array_column($cases, 'must_block'));
        $this->assertContains('de', array_column($cases, 'language'));
    }

    public function test_replay_metrics_detect_missed_intent_and_required_block(): void
    {
        $metrics = app(ProductQuestionDraftReplaySuite::class)->evaluate([
            ['id' => 'pq-001', 'intents' => ['ask_pockets'], 'blocked' => false, 'duration_ms' => 100, 'cost' => 0.01],
            ['id' => 'pq-010', 'intents' => ['request_custom_color'], 'blocked' => false, 'duration_ms' => 200, 'cost' => 0.02],
        ]);

        $this->assertSame(50, $metrics['suite_size']);
        $this->assertSame(2, $metrics['evaluated']);
        $this->assertSame(0.04, $metrics['prediction_coverage']);
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

        $this->assertSame(0.02, $metrics['fact_error_rate']);
        $this->assertSame(0.02, $metrics['policy_violation_rate']);
        $this->assertSame(0.0, $metrics['false_block_rate']);
        $this->assertSame(0.0, $metrics['missed_required_block_rate']);
        $this->assertSame(1.0, $metrics['quality_review_coverage']);
    }
}
