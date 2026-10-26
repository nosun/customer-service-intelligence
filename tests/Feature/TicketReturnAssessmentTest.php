<?php

namespace Tests\Feature;

use App\Services\Agents\TicketReturnAssessment;
use App\Services\Agents\TicketSiteReturnPolicy;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class TicketReturnAssessmentTest extends TestCase
{
    public function test_confirmed_conditions_select_stage_two_without_requiring_a_street_address_or_fee_allocation(): void
    {
        $result = app(TicketReturnAssessment::class)->record($this->assessment(), ['event:1', 'policy:window@1']);
        $this->assertSame('offer_options', $result['stage']);
        $this->assertSame('RET-02', $result['template_id']);
        $this->assertContains('request_customer_choice', $result['reply_requirements']);
        $this->assertNotContains('provide_verified_address', $result['reply_requirements']);
        $this->assertSame('eligible_proposal', $result['routing_rule']);
        $this->assertSame('1.1.0', $result['strategy_version']);
        $this->assertSame(hash_file('sha256', resource_path('data/ticket-return-playbook-v1.json')), $result['definition_hash']);
    }

    public function test_every_versioned_routing_case_matches_the_executable_definition(): void
    {
        $returns = app(TicketReturnAssessment::class);
        $suite = json_decode(file_get_contents(resource_path('data/ticket-return-routing-cases-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($suite['cases'] as $case) {
            $input = array_replace($suite['base_assessment'], $case['changes']);
            foreach ($input['conditions'] as &$condition) {
                $condition['status'] = $case['condition_statuses'][$condition['key']] ?? $condition['status'];
            }
            unset($condition);
            $result = $returns->record($input, $suite['evidence_refs']);
            $this->assertSame($case['expected_stage'], $result['stage'], $case['key']);
            $this->assertSame($case['expected_rule'], $result['routing_rule'], $case['key']);
            if ($case['key'] === 'accepted_with_new_conflict') {
                $this->assertTrue($result['requires_internal_review']);
            }
        }
    }

    public function test_invalid_definition_edges_capabilities_duplicate_rules_and_uncovered_nodes_are_rejected(): void
    {
        $returns = app(TicketReturnAssessment::class);
        foreach (['edge', 'capability', 'duplicate', 'fallback', 'unreachable', 'guard'] as $variant) {
            $definition = $returns->playbook();
            match ($variant) {
                'edge' => $definition['edges'][0]['to'] = 'unknown',
                'capability' => $definition['stages']['offer_options']['required_evidence'][] = 'unknown',
                'duplicate' => $definition['routing'][1]['id'] = $definition['routing'][0]['id'],
                'fallback' => $definition['routing'][0]['when'] = ['always' => true],
                'unreachable' => $definition['routing'][0]['stage'] = 'track_return',
                'guard' => $definition['routing'][0]['when'] = ['positions' => ['unconditional_guess']],
            };
            try {
                $returns->validateDefinition($definition);
                $this->fail('Invalid definition accepted: '.$variant);
            } catch (RuntimeException|ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_genuine_missing_conditions_conflicts_and_outside_window_have_distinct_routes(): void
    {
        foreach (['missing' => 'collect_conditions', 'failed' => 'exception_review', 'conflict' => 'exception_review'] as $status => $expected) {
            $input = $this->assessment();
            $input['conditions'][5]['status'] = $status;
            $input['conditions'][5]['explanation'] = 'Request window needs specific assessment.';
            $this->assertSame($expected, app(TicketReturnAssessment::class)->record($input, ['event:1', 'policy:window@1'])['stage']);
        }
    }

    public function test_accepted_offer_and_dispatch_do_not_restart_the_initial_checklist(): void
    {
        foreach (['option_accepted' => 'send_instructions', 'return_shipped' => 'track_return', 'return_received' => 'review_refund'] as $position => $stage) {
            $input = $this->assessment();
            $input['customer_position'] = $position;
            $input['conditions'][2]['status'] = 'missing';
            $this->assertSame($stage, app(TicketReturnAssessment::class)->record($input, ['event:1', 'policy:window@1'])['stage']);
        }
    }

    public function test_conditional_acceptance_stays_in_negotiation_and_does_not_release_return_instructions(): void
    {
        $input = $this->assessment();
        $input['customer_position'] = 'conditional_acceptance';
        $result = app(TicketReturnAssessment::class)->record($input, ['event:1', 'policy:window@1']);
        $this->assertSame('offer_options', $result['stage']);
        $this->assertSame('RET-02', $result['template_id']);
    }

    public function test_tool_rejects_incomplete_advanced_assessment_and_accepts_explicit_unknown_history(): void
    {
        $returns = app(TicketReturnAssessment::class);
        $input = $this->assessment();
        $input['customer_position'] = 'return_received';
        $input['conditions'] = [];
        $invalid = $returns->recordForTool($input, ['event:1', 'policy:window@1']);
        $this->assertSame('invalid_assessment', $invalid['status']);
        $this->assertArrayNotHasKey('assessment', $invalid);
        $this->assertSame([], $invalid['evidence_refs']);
        $this->assertStringContainsString('all six', $invalid['correction']);

        $input['conditions'] = array_map(fn ($condition) => [...$condition, 'status' => 'missing'], $this->assessment()['conditions']);
        $corrected = $returns->recordForTool($input, ['event:1', 'policy:window@1']);
        $this->assertSame('review_refund', $corrected['assessment']['stage']);
        $this->assertSame(['assessment:return'], $corrected['evidence_refs']);
    }

    public function test_agent_view_cannot_leak_evaluation_case_to_stage_mappings(): void
    {
        $returns = app(TicketReturnAssessment::class);
        $view = $returns->agentPlaybook();
        $this->assertArrayNotHasKey('maintenance', $view);
        foreach ($view['stages'] as $node) {
            $this->assertArrayNotHasKey('evaluation_cases', $node);
        }
        $this->assertStringNotContainsString('confirmed_context', json_encode($view));
        $this->assertNotEmpty($returns->playbook()['stages']['offer_options']['evaluation_cases']);
    }

    public function test_unread_evidence_and_duplicate_condition_coverage_cannot_support_a_stage(): void
    {
        foreach (['unread', 'duplicate', 'unfounded_progress', 'strategy_as_fact'] as $variant) {
            $input = $this->assessment();
            if ($variant === 'unread') {
                $input['conditions'][0]['evidence_refs'] = ['event:another-ticket'];
            } elseif ($variant === 'duplicate') {
                $input['conditions'][0]['key'] = $input['conditions'][1]['key'];
            } elseif ($variant === 'unfounded_progress') {
                $input['customer_position'] = 'option_accepted';
                $input['position_evidence_refs'] = [];
            } else {
                $input['conditions'][0]['evidence_refs'] = ['strategy:return@1.0.0'];
            }
            try {
                app(TicketReturnAssessment::class)->record($input, ['event:1', 'policy:window@1', 'strategy:return@1.0.0']);
                $this->fail('Unfounded stage must be rejected.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_site_policy_reads_only_the_configured_source_and_parses_json_without_executing_scripts(): void
    {
        Http::preventStrayRequests();
        $text = '<p>Request a return within 14 days; keep the dress unchanged.</p><p>'.str_repeat('Current return terms. ', 8).'</p>';
        $html = '<script>$d.article = '.json_encode(['article' => ['content_en' => $text.' {quoted "content"}']])."\n</script><script>malicious();</script>";
        Http::fake(['www.dressafford.com/article/return-policy.html' => Http::response($html)]);
        $service = app(TicketSiteReturnPolicy::class);
        $this->assertSame('unavailable', $service->read('evil.test')['status']);
        Http::assertNothingSent();
        $result = $service->read('dressafford.com');
        $this->assertSame('read', $result['status']);
        $this->assertStringContainsString('14 days;', $result['content']);
        $this->assertStringNotContainsString('malicious', $result['content']);
        $this->assertSame('site_policy:return@'.hash('sha256', $result['content']), $result['evidence_refs'][0]);
        Http::assertSentCount(1);
    }

    public function test_redirects_unknown_formats_and_network_failures_are_gaps_not_policy_evidence(): void
    {
        Http::preventStrayRequests();
        foreach ([Http::response('', 302, ['Location' => 'http://127.0.0.1']), Http::response('<html>Login</html>'), Http::response('Unavailable', 503)] as $response) {
            Http::fake(fn () => $response);
            $result = app(TicketSiteReturnPolicy::class)->read('dressafford.com');
            $this->assertSame('unavailable', $result['status']);
            $this->assertSame([], $result['evidence_refs']);
        }
    }

    private function assessment(): array
    {
        return ['applicable' => true, 'reason' => 'Prior questions have been answered in context; the request is timely.',
            'evidence_refs' => ['event:1', 'policy:window@1'],
            'conditions' => array_map(fn ($key) => ['key' => $key, 'status' => 'satisfied', 'explanation' => 'Confirmed from customer reply and applicable policy.', 'evidence_refs' => ['event:1', 'policy:window@1']], TicketReturnAssessment::CONDITIONS),
            'customer_position' => 'conditions_confirmed', 'position_evidence_refs' => ['event:1'], 'exception_reason' => ''];
    }
}
