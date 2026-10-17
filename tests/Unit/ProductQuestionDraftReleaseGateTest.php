<?php

namespace Tests\Unit;

use App\Services\ProductQuestions\ProductQuestionDraftReleaseGate;
use Tests\TestCase;

class ProductQuestionDraftReleaseGateTest extends TestCase
{
    public function test_gate_requires_full_predictions_human_quality_review_and_zero_critical_errors(): void
    {
        $result = app(ProductQuestionDraftReleaseGate::class)->evaluate([
            'prediction_coverage' => 0.98,
            'quality_review_coverage' => 0.5,
            'workflow_failure_rate' => 0.02,
            'fact_error_rate' => 0.02,
            'policy_violation_rate' => 0,
            'missed_required_block_rate' => 0,
            'missed_intent_rate' => 0.04,
        ]);

        $this->assertFalse($result['passed']);
        $this->assertCount(5, $result['failures']);
    }

    public function test_gate_passes_the_phase_one_quality_floor(): void
    {
        $result = app(ProductQuestionDraftReleaseGate::class)->evaluate([
            'prediction_coverage' => 1,
            'quality_review_coverage' => 1,
            'workflow_failure_rate' => 0,
            'fact_error_rate' => 0,
            'policy_violation_rate' => 0,
            'missed_required_block_rate' => 0,
            'missed_intent_rate' => 0.02,
        ]);

        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['failures']);
    }
}
