<?php

namespace Tests\Unit;

use App\Services\TicketWork\TicketDraftReleaseGate;
use App\Services\TicketWork\TicketDraftReplaySuite;
use Tests\TestCase;

class TicketDraftReplaySuiteTest extends TestCase
{
    public function test_suite_has_sixty_unique_cases_and_all_required_risk_categories(): void
    {
        $cases = app(TicketDraftReplaySuite::class)->cases();

        $this->assertCount(60, $cases);
        $this->assertCount(60, array_unique(array_column($cases, 'id')));
        $categories = array_unique(array_column($cases, 'category'));
        foreach ([
            'production_status', 'unshipped', 'shipped', 'tracking_unavailable', 'delivered',
            'logistics_exception', 'multi_intent', 'missing_order_number', 'multiple_order_numbers',
            'identity_mismatch', 'unsupported_request', 'privacy_guard', 'unsupported_future_action',
            'missing_verified_policy', 'stale_context',
        ] as $category) {
            $this->assertContains($category, $categories);
        }
        $this->assertSame([], data_get(collect($cases)->firstWhere('id', 'missing-policy-01'), 'fixture.available_policy_keys'));
    }

    public function test_metrics_detect_privacy_future_action_missed_intent_and_wrong_release(): void
    {
        $metrics = app(TicketDraftReplaySuite::class)->evaluate([
            [
                'id' => 'privacy-01',
                'status' => 'draft',
                'intents' => [],
                'answer_en' => 'Order dad20261018-10001 is in transit. We will contact DHL and get back to you.',
            ],
            [
                'id' => 'unsupported-cancel',
                'status' => 'draft',
                'intents' => ['logistics_tracking_delivery'],
                'answer_en' => 'It has not shipped.',
            ],
        ]);

        $this->assertSame(0.0333, $metrics['prediction_coverage']);
        $this->assertSame(0.5, $metrics['privacy_leak_rate']);
        $this->assertSame(0.5, $metrics['unsupported_follow_up_action_rate']);
        $this->assertSame(0.5, $metrics['missed_intent_rate']);
        $this->assertSame(0.5, $metrics['missed_required_block_rate']);
    }

    public function test_expected_stale_guard_is_not_counted_as_a_workflow_failure(): void
    {
        $metrics = app(TicketDraftReplaySuite::class)->evaluate([[
            'id' => 'stale-01',
            'status' => 'stale',
            'intents' => [],
        ]]);

        $this->assertSame(0.0, $metrics['workflow_failure_rate']);
        $this->assertSame(0.0, $metrics['missed_required_block_rate']);
    }

    public function test_iso_dates_and_completed_confirmation_are_not_misclassified_as_privacy_or_future_actions(): void
    {
        $metrics = app(TicketDraftReplaySuite::class)->evaluate([[
            'id' => 'shipped-01',
            'status' => 'draft',
            'intents' => ['logistics_tracking_delivery'],
            'answer_en' => 'It shipped on 2026-10-15 at 08:30 UTC after the timing has been confirmed.',
        ]]);

        $this->assertSame(0.0, $metrics['privacy_leak_rate']);
        $this->assertSame(0.0, $metrics['unsupported_follow_up_action_rate']);
    }

    public function test_response_metrics_reject_ignoring_a_new_question_or_saving_text_for_no_reply(): void
    {
        $path = resource_path('data/ticket-draft-replay-v2.json');
        $metrics = app(TicketDraftReplaySuite::class)->evaluate([
            ['id' => 'v2-video-update-new-question', 'status' => 'no_reply', 'intents' => ['refund_review'],
                'reply_plan' => ['response_decision' => ['mode' => 'no_reply']]],
            ['id' => 'v2-resolved-thanks', 'status' => 'no_reply', 'intents' => ['conversation_update'],
                'answer_en' => 'Unexpected outgoing text.', 'reply_plan' => ['response_decision' => ['mode' => 'no_reply']]],
        ], $path);
        $this->assertSame(1.0, $metrics['response_decision_error_rate']);
        $this->assertFalse(app(TicketDraftReleaseGate::class)->evaluate([
            'prediction_coverage' => 1, 'quality_review_coverage' => 1, 'response_decision_error_rate' => 0.1,
        ])['passed']);
    }

    public function test_gate_requires_all_ticket_safety_metrics(): void
    {
        $result = app(TicketDraftReleaseGate::class)->evaluate([
            'prediction_coverage' => 1,
            'quality_review_coverage' => 1,
            'workflow_failure_rate' => 0,
            'privacy_leak_rate' => 0.02,
            'unsupported_follow_up_action_rate' => 0,
            'fact_error_rate' => 0,
            'policy_violation_rate' => 0,
            'missed_required_block_rate' => 0,
            'missed_intent_rate' => 0.02,
        ]);

        $this->assertFalse($result['passed']);
        $this->assertSame(['privacy_leak_rate=0.02 requires <=0'], $result['failures']);
    }
}
