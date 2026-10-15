<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\ConversationEvent;
use App\Services\DecisionCases\DecisionCaseOutputValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DecisionCaseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_one_case_with_multiple_evidenced_nodes_and_skips_unchanged_input(): void
    {
        config([
            'services.minimax.api_key' => 'secret',
            'services.minimax.base_url' => 'https://api.minimax.test/v1',
            'services.minimax.model' => 'MiniMax-M3',
        ]);
        $account = ChannelAccount::create([
            'channel' => 'helpdesk',
            'external_account_id' => '12345',
        ]);
        $conversation = Conversation::create([
            'channel_account_id' => $account->id,
            'external_id' => 'ticket-case-1',
            'site_key' => 'dressafford.com',
            'silo' => 'tickets',
            'status' => 'pending',
            'subject' => 'Cancel and refund',
            'raw_payload' => [],
        ]);
        $request = $this->event($conversation, 'event-1', 'client', 'Please cancel my order.');
        $decision = $this->event($conversation, 'event-2', 'agent', 'The order is in production, so we need approval.');
        $followUp = $this->event($conversation, 'event-3', 'client', 'If cancellation is impossible, can I return it?');
        $answer = $this->event($conversation, 'event-4', 'agent', 'Please contact us after delivery.');
        ConversationAnalysis::create([
            'conversation_id' => $conversation->id,
            'primary_category' => 'production',
            'primary_problem_type' => 'production_cancellation',
            'urgency' => 'high',
            'category_counts' => ['production' => 1],
            'problem_type_counts' => ['production_cancellation' => 1],
            'analyzer_version' => 'test',
            'analyzed_at' => now(),
        ]);
        Http::fake([
            '*' => Http::response([
                'id' => 'minimax-request-1',
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'case_summary' => '客户先申请取消，随后询问退货。',
                        'primary_category' => 'production',
                        'primary_problem_type' => 'production_cancellation',
                        'case_risk' => 'normal',
                        'nodes' => [
                            [
                                'sequence' => 1,
                                'problem_type' => 'production_cancellation',
                                'scenario_key' => 'cancel_in_production',
                                'request_summary' => '客户请求取消订单。',
                                'observed_facts' => [[
                                    'name' => 'production_status',
                                    'value' => '客服称订单正在生产',
                                    'evidence_event_ids' => [$decision->id],
                                ]],
                                'missing_facts' => ['是否获得取消审批'],
                                'observed_decision' => 'escalated',
                                'observed_action' => '需要进一步审批',
                                'stated_reason' => '订单正在生产',
                                'observed_outcome' => null,
                                'is_exception' => false,
                                'evidence' => [
                                    ['event_id' => $request->id, 'role' => 'request'],
                                    ['event_id' => $decision->id, 'role' => 'decision'],
                                    ['event_id' => $decision->id, 'role' => 'reason'],
                                ],
                                'uncertainties' => ['取消审批结果未知'],
                                'extraction_confidence' => 0.92,
                            ],
                            [
                                'sequence' => 2,
                                'problem_type' => 'after_sales_return_refund',
                                'scenario_key' => 'return_after_delivery',
                                'request_summary' => '客户转而询问收货后退货。',
                                'observed_facts' => [],
                                'missing_facts' => ['商品状态', '退货资格'],
                                'observed_decision' => 'conditional',
                                'observed_action' => null,
                                'stated_reason' => null,
                                'observed_outcome' => null,
                                'is_exception' => false,
                                'evidence' => [
                                    ['event_id' => $followUp->id, 'role' => 'request'],
                                    ['event_id' => $answer->id, 'role' => 'decision'],
                                ],
                                'uncertainties' => [],
                                'extraction_confidence' => 0.87,
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE)],
                ]],
                'usage' => [
                    'prompt_tokens' => 700,
                    'completion_tokens' => 200,
                    'total_tokens' => 900,
                ],
            ]),
        ]);

        $arguments = ['--ticket-id' => 'ticket-case-1'];
        $this->artisan('helpdesk:cases:build', $arguments)
            ->expectsOutputToContain('准备处理 1 张 Ticket（匹配 1 张）')
            ->expectsOutputToContain('[1/1 100%] | Ticket ticket-case-1 | 完成 | 节点 2 |')
            ->expectsOutputToContain('"total_tokens": 900')
            ->assertSuccessful();
        $this->artisan('helpdesk:cases:build', $arguments)
            ->expectsOutputToContain('跳过：内容未变化')
            ->expectsOutputToContain('"total_tokens": 0')
            ->assertSuccessful();

        $this->assertDatabaseCount('decision_cases', 1);
        $this->assertDatabaseCount('decision_nodes', 2);
        $this->assertDatabaseCount('decision_node_evidence', 5);
        $this->assertDatabaseCount('decision_case_runs', 1);
        $this->assertDatabaseHas('decision_nodes', [
            'sequence' => 2,
            'scenario_key' => 'return_after_delivery',
            'observed_decision' => 'conditional',
        ]);
        $this->assertDatabaseHas('decision_cases', ['case_risk' => 'high']);
        Http::assertSentCount(1);
    }

    public function test_validator_rejects_evidence_from_another_ticket(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid evidence');

        (new DecisionCaseOutputValidator)->validate([
            'case_summary' => 'test',
            'primary_category' => 'unknown',
            'primary_problem_type' => null,
            'case_risk' => 'normal',
            'nodes' => [[
                'sequence' => 1,
                'request_summary' => 'test',
                'observed_facts' => [],
                'missing_facts' => [],
                'observed_decision' => 'unknown',
                'evidence' => [['event_id' => 999, 'role' => 'request']],
                'uncertainties' => [],
                'extraction_confidence' => 0.5,
            ]],
        ], [1]);
    }

    public function test_validator_adds_request_role_when_ai_cites_a_client_event_with_another_role(): void
    {
        $output = (new DecisionCaseOutputValidator)->validate([
            'case_summary' => 'test',
            'primary_category' => 'production',
            'primary_problem_type' => 'production_cancellation',
            'case_risk' => 'high',
            'nodes' => [[
                'sequence' => 1,
                'request_summary' => '客户请求取消。',
                'observed_facts' => [],
                'missing_facts' => [],
                'observed_decision' => 'unknown',
                'evidence' => [['event_id' => 1, 'role' => 'fact']],
                'uncertainties' => [],
                'extraction_confidence' => 0.8,
            ]],
        ], [1], [1 => 'client']);

        $this->assertContains(['event_id' => 1, 'role' => 'request'], $output['nodes'][0]['evidence']);
    }

    public function test_validator_merges_an_agent_only_follow_up_into_the_preceding_customer_node(): void
    {
        $output = (new DecisionCaseOutputValidator)->validate([
            'case_summary' => 'test',
            'primary_category' => 'after_sales',
            'primary_problem_type' => 'after_sales_return_refund',
            'case_risk' => 'high',
            'nodes' => [
                [
                    'sequence' => 1,
                    'request_summary' => '客户申请退货。',
                    'observed_facts' => [],
                    'missing_facts' => [],
                    'observed_decision' => 'unknown',
                    'evidence' => [['event_id' => 1, 'role' => 'request']],
                    'uncertainties' => [],
                    'extraction_confidence' => 0.9,
                ],
                [
                    'sequence' => 2,
                    'request_summary' => '客服提供退货说明。',
                    'observed_facts' => [],
                    'missing_facts' => [],
                    'observed_decision' => 'conditional',
                    'observed_action' => '提供退货步骤',
                    'evidence' => [['event_id' => 2, 'role' => 'action']],
                    'uncertainties' => [],
                    'extraction_confidence' => 0.8,
                ],
            ],
        ], [1, 2], [1 => 'client', 2 => 'agent']);

        $this->assertCount(1, $output['nodes']);
        $this->assertSame('conditional', $output['nodes'][0]['observed_decision']);
        $this->assertSame('提供退货步骤', $output['nodes'][0]['observed_action']);
        $this->assertContains(['event_id' => 2, 'role' => 'action'], $output['nodes'][0]['evidence']);
    }

    private function event(
        Conversation $conversation,
        string $externalId,
        string $authorType,
        string $body,
    ): ConversationEvent {
        return ConversationEvent::create([
            'conversation_id' => $conversation->id,
            'external_id' => $externalId,
            'event_type' => 'message',
            'occurred_at' => now(),
            'author_type' => $authorType,
            'is_private' => false,
            'body_text' => $body,
            'content_hash' => hash('sha256', $body),
            'raw_payload' => [],
        ]);
    }
}
