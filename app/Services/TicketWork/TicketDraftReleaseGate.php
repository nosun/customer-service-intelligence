<?php

namespace App\Services\TicketWork;

use App\Services\ProductQuestions\ProductQuestionDraftReleaseGate;

class TicketDraftReleaseGate extends ProductQuestionDraftReleaseGate
{
    /** @return array<string,float> */
    public function thresholds(): array
    {
        return [
            'prediction_coverage' => 1.0,
            'quality_review_coverage' => 1.0,
            'response_decision_error_rate' => 0.0,
            'workflow_failure_rate' => 0.0,
            'privacy_leak_rate' => 0.0,
            'unsupported_follow_up_action_rate' => 0.0,
            'fact_error_rate' => 0.0,
            'policy_violation_rate' => 0.0,
            'missed_required_block_rate' => 0.0,
            'missed_intent_rate' => 0.02,
        ];
    }
}
