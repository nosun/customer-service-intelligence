<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\ConversationEvent;
use App\Models\KnowledgeArticle;
use App\Services\TicketWork\N8nTicketDraftService;
use App\Services\TicketWork\TicketWorkItemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TicketDraftRuntimeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.n8n.work_internal_api_token' => 'ticket-work-token',
            'services.console_new.base_url' => 'https://console.test/api/admin',
            'services.console_new.token' => 'read-only-token',
        ]);
    }

    public function test_runtime_workflow_uses_stage_bounded_context_live_facts_and_only_verified_policies(): void
    {
        $ticket = $this->ticket();
        $this->message($ticket, 'client', 'My email is customer@example.test. What is happening with dad20261018-10001?', now()->subMinutes(4));
        $this->message($ticket, 'agent', 'Hi Melissa, we received your question.', now()->subMinutes(3));
        $this->message($ticket, 'agent', 'Private factory note', now()->subMinutes(2), true);
        $trigger = $this->message($ticket, 'client', 'Is it still in production, and has dad20261018-10001 shipped?', now()->subMinute());
        $verified = $this->policy('production_status_eta_verification', 'verified');
        $this->policy('unreviewed_historical_reply', 'observed');
        $item = app(TicketWorkItemManager::class)->create(
            $ticket,
            7,
            N8nTicketDraftService::WORKFLOW_KEY,
            N8nTicketDraftService::WORKFLOW_VERSION,
        );

        $start = $this->withToken('ticket-work-token')->postJson(
            "/api/internal/n8n/ticket-work-items/{$item->work_item_key}/start",
            [
                'execution_id' => 'execution-ticket-1',
                'workflow_key' => N8nTicketDraftService::WORKFLOW_KEY,
                'workflow_version' => N8nTicketDraftService::WORKFLOW_VERSION,
            ],
        )->assertOk();
        $start->assertJsonPath('context.ticket.trigger_event_id', $trigger->id)
            ->assertJsonFragment(['key' => $verified->key])
            ->assertJsonMissing(['key' => 'unreviewed_historical_reply'])
            ->assertJsonMissing(['text' => 'Private factory note']);
        $encodedContext = json_encode($start->json('context.ticket'));
        $this->assertStringContainsString('[ORDER_NUMBER]', $encodedContext);
        $this->assertStringContainsString('[EMAIL]', $encodedContext);
        $this->assertStringNotContainsString('dad20261018-10001', $encodedContext);
        $this->assertStringNotContainsString('customer@example.test', $encodedContext);
        $this->assertStringNotContainsString('Melissa', $encodedContext);
        $this->assertStringContainsString('[CUSTOMER_NAME]', $encodedContext);

        Http::fake([
            'https://console.test/api/admin/orders/dad20261018-10001' => Http::response($this->orderResponse(), 200),
            'https://console.test/api/admin/work-orders*' => Http::response($this->workOrderResponse(), 200),
        ]);
        $plan = [
            'clauses' => [
                ['text' => 'Is it still in production?', 'intent' => 'production_order_status'],
                ['text' => 'Has it shipped?', 'intent' => 'logistics_tracking_delivery'],
            ],
            'intents' => ['production_order_status', 'logistics_tracking_delivery'],
            'policy_keys' => [],
            'unsupported_requests' => [],
            'customer_language' => 'en',
        ];
        $this->withToken('ticket-work-token')->postJson(
            "/api/internal/n8n/ticket-work-items/{$item->work_item_key}/facts",
            ['execution_id' => 'execution-ticket-1', 'plan' => $plan],
        )->assertOk()
            ->assertJsonPath('missing_facts', [])
            ->assertJsonFragment(['key' => 'production.statuses'])
            ->assertJsonFragment(['key' => 'order.shipping_status']);
        $this->withToken('ticket-work-token')->postJson(
            "/api/internal/n8n/ticket-work-items/{$item->work_item_key}/policies",
            ['execution_id' => 'execution-ticket-1', 'policy_keys' => [$verified->key, 'unreviewed_historical_reply']],
        )->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', $verified->key);

        $result = $this->withToken('ticket-work-token')->postJson(
            "/api/internal/n8n/ticket-work-items/{$item->work_item_key}/complete",
            [
                'execution_id' => 'execution-ticket-1',
                'review' => [
                    'approved' => true,
                    'must_block' => false,
                    'covered_intents' => ['production_order_status', 'logistics_tracking_delivery'],
                    'final_answer' => 'Your order is currently in production and has not shipped yet.',
                    'safety_flags' => [],
                ],
            ],
        )->assertOk();

        $result->assertJsonPath('draft_status', 'draft')
            ->assertJsonPath('requires_human_review', true);
        $this->assertDatabaseHas('conversation_drafts', [
            'conversation_id' => $ticket->id,
            'work_item_id' => $item->id,
            'source' => 'n8n',
            'status' => 'draft',
            'body' => 'Your order is currently in production and has not shipped yet.',
        ]);
        $this->assertDatabaseHas('work_items', ['id' => $item->id, 'status' => 'completed']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'helpdesk'));
    }

    public function test_missing_facts_or_unsupported_requests_produce_a_blocked_local_draft(): void
    {
        config(['services.console_new.base_url' => '', 'services.console_new.token' => '']);
        $ticket = $this->ticket();
        $this->message($ticket, 'client', 'Where is order dad20261018-10001, and can you cancel it?', now());
        $policy = $this->policy('shipped_order_tracking_and_eta_communication', 'verified');
        $item = app(TicketWorkItemManager::class)->create(
            $ticket,
            8,
            N8nTicketDraftService::WORKFLOW_KEY,
            N8nTicketDraftService::WORKFLOW_VERSION,
        );
        $service = app(N8nTicketDraftService::class);
        $service->start($item, 'execution-ticket-2');
        $plan = [
            'clauses' => [
                ['text' => 'Where is the order?', 'intent' => 'logistics_tracking_delivery'],
                ['text' => 'Can you cancel it?', 'intent' => 'unsupported'],
            ],
            'intents' => ['logistics_tracking_delivery'],
            'policy_keys' => [$policy->key],
            'unsupported_requests' => ['cancel order'],
            'customer_language' => 'en',
        ];
        $service->resolveFacts($item, 'execution-ticket-2', $plan);
        $service->policies($item, 'execution-ticket-2', [$policy->key]);
        $result = $service->complete($item, 'execution-ticket-2', [
            'approved' => true,
            'must_block' => false,
            'covered_intents' => ['logistics_tracking_delivery'],
            'final_answer' => 'We will ask our team to check and cancel it.',
            'safety_flags' => [],
        ]);

        $this->assertSame('blocked', $result['draft_status']);
        $this->assertContains('The Ticket contains a request outside the released workflow scope.', $result['safety_flags']);
        $this->assertContains('Required current order, production, or shipment facts are missing.', $result['safety_flags']);
        $this->assertContains('The Draft promises a future human, factory, or carrier action that was not executed.', $result['safety_flags']);
        $this->assertDatabaseHas('conversation_drafts', ['work_item_id' => $item->id, 'status' => 'blocked']);
    }

    public function test_new_message_makes_a_leased_work_item_stale_instead_of_using_old_context(): void
    {
        $ticket = $this->ticket();
        $this->message($ticket, 'client', 'Where is dad20261018-10001?', now()->subMinute());
        $item = app(TicketWorkItemManager::class)->create(
            $ticket,
            9,
            N8nTicketDraftService::WORKFLOW_KEY,
            N8nTicketDraftService::WORKFLOW_VERSION,
        );
        $this->message($ticket, 'client', 'There is another update.', now());

        $this->expectExceptionMessage('changed before execution');
        try {
            app(N8nTicketDraftService::class)->start($item, 'execution-ticket-3');
        } finally {
            $this->assertSame('stale', $item->fresh()->status);
        }
    }

    public function test_unsupported_only_ticket_is_blocked_instead_of_failing_the_workflow(): void
    {
        $ticket = $this->ticket();
        $this->message($ticket, 'client', 'Please cancel my order.', now());
        $item = app(TicketWorkItemManager::class)->create(
            $ticket,
            10,
            N8nTicketDraftService::WORKFLOW_KEY,
            N8nTicketDraftService::WORKFLOW_VERSION,
        );
        $service = app(N8nTicketDraftService::class);
        $service->start($item, 'execution-ticket-4');
        $plan = [
            'clauses' => [['text' => 'Please cancel my order.', 'intent' => 'unsupported']],
            'intents' => [],
            'policy_keys' => [],
            'unsupported_requests' => [],
            'customer_language' => 'en',
        ];
        $facts = $service->resolveFacts($item, 'execution-ticket-4', $plan);
        $service->policies($item, 'execution-ticket-4', []);
        $result = $service->complete($item, 'execution-ticket-4', [
            'approved' => false,
            'must_block' => true,
            'covered_intents' => [],
            'final_answer' => 'This request requires separate human review.',
            'safety_flags' => [],
        ]);

        $this->assertSame([], $facts['facts']);
        $this->assertSame('blocked', $result['draft_status']);
        $this->assertSame('completed', $item->fresh()->status);
    }

    public function test_replay_work_item_uses_checksum_bound_frozen_facts_without_live_console_lookup(): void
    {
        $ticket = $this->ticket();
        $this->message($ticket, 'client', 'Where is dad20261018-10001?', now());
        $policy = $this->policy('shipped_order_tracking_and_eta_communication', 'verified');
        $item = app(TicketWorkItemManager::class)->create(
            $ticket,
            11,
            N8nTicketDraftService::WORKFLOW_KEY,
            N8nTicketDraftService::WORKFLOW_VERSION,
        );
        $fixture = [
            'identity_match' => true,
            'shipping_status' => 'Delivered',
            'tracking_available' => true,
            'logistics_status' => 'DELIVERED',
            'delivered_at' => '2026-10-16T11:20:00Z',
            'available_policy_keys' => [$policy->key],
        ];
        $item->update(['input_snapshot' => [
            ...$item->input_snapshot,
            'replay_mode' => true,
            'replay_fixture' => $fixture,
            'replay_fixture_checksum' => hash('sha256', json_encode($fixture, JSON_THROW_ON_ERROR)),
        ]]);
        Http::fake(fn () => Http::response([], 500));
        $service = app(N8nTicketDraftService::class);
        $service->start($item, 'execution-replay-fixture');
        $facts = $service->resolveFacts($item, 'execution-replay-fixture', [
            'clauses' => [['text' => 'Where is it?', 'intent' => 'logistics_tracking_delivery']],
            'intents' => ['logistics_tracking_delivery'],
            'policy_keys' => [],
            'unsupported_requests' => [],
            'customer_language' => 'en',
        ]);

        $this->assertSame('Delivered', collect($facts['facts'])->firstWhere('key', 'order.shipping_status')['value']);
        $this->assertSame('ticket-replay-fixture', collect($facts['facts'])->firstWhere('key', 'order.shipping_status')['source']);
        Http::assertNothingSent();
    }

    public function test_replay_fixture_with_unknown_identity_blocks_the_draft(): void
    {
        $ticket = $this->ticket();
        $this->message($ticket, 'client', 'Is dad20261018-10001 in production?', now());
        $policy = $this->policy('production_status_eta_verification', 'verified');
        $item = app(TicketWorkItemManager::class)->create(
            $ticket,
            12,
            N8nTicketDraftService::WORKFLOW_KEY,
            N8nTicketDraftService::WORKFLOW_VERSION,
        );
        $fixture = [
            'identity_match' => null,
            'order_status' => 'Processing',
            'shipping_status' => 'Not shipped',
            'production_statuses' => [['work_process' => 'In production', 'product_status' => 'Normal']],
            'available_policy_keys' => [$policy->key],
        ];
        $item->update(['input_snapshot' => [
            ...$item->input_snapshot,
            'replay_mode' => true,
            'replay_fixture' => $fixture,
            'replay_fixture_checksum' => hash('sha256', json_encode($fixture, JSON_THROW_ON_ERROR)),
        ]]);
        $service = app(N8nTicketDraftService::class);
        $service->start($item, 'execution-unknown-identity');
        $facts = $service->resolveFacts($item, 'execution-unknown-identity', [
            'clauses' => [['text' => 'Is it in production?', 'intent' => 'production_order_status']],
            'intents' => ['production_order_status'],
            'policy_keys' => [],
            'unsupported_requests' => [],
            'customer_language' => 'en',
        ]);
        $service->policies($item, 'execution-unknown-identity', [$policy->key]);
        $result = $service->complete($item, 'execution-unknown-identity', [
            'approved' => true,
            'must_block' => false,
            'covered_intents' => ['production_order_status'],
            'final_answer' => 'The order is in production.',
            'safety_flags' => [],
        ]);

        $this->assertContains('order.identity_verified', $facts['missing_facts']);
        $this->assertSame('blocked', $result['draft_status']);
    }

    private function ticket(): Conversation
    {
        $account = ChannelAccount::create([
            'channel' => 'helpdesk',
            'external_account_id' => 'ticket-runtime-account',
        ]);
        $ticket = Conversation::create([
            'channel_account_id' => $account->id,
            'external_id' => uniqid('ticket-runtime-', true),
            'external_short_id' => 'RT-1001',
            'site_key' => 'dressafford.com',
            'requester_email' => 'customer@example.test',
            'requester_snapshot' => ['name' => 'Melissa'],
            'subject' => 'Order and delivery status',
            'silo' => 'tickets',
            'status' => 'open',
            'raw_payload' => [],
        ]);
        ConversationAnalysis::create([
            'conversation_id' => $ticket->id,
            'primary_category' => 'logistics',
            'primary_problem_type' => 'logistics_tracking_delivery',
            'urgency' => 'normal',
            'analyzer_version' => 'test',
            'analyzed_at' => now(),
        ]);

        return $ticket->load('analysis');
    }

    private function message(Conversation $ticket, string $author, string $body, $occurredAt, bool $private = false): ConversationEvent
    {
        return ConversationEvent::create([
            'conversation_id' => $ticket->id,
            'external_id' => uniqid('event-runtime-', true),
            'event_type' => 'message',
            'occurred_at' => $occurredAt,
            'author_type' => $author,
            'is_private' => $private,
            'body_text' => $body,
            'content_hash' => hash('sha256', uniqid($body, true)),
            'raw_payload' => [],
        ]);
    }

    private function policy(string $key, string $status): KnowledgeArticle
    {
        $article = KnowledgeArticle::create([
            'key' => $key,
            'title' => str_replace('_', ' ', $key),
            'knowledge_type' => 'process',
            'status' => $status,
            'site_key' => null,
            'applicability' => 'Existing orders',
            'required_facts' => ['order status'],
            'policy_rules' => ['Use current order facts.'],
            'decision_tree' => [],
            'reply_strategy' => [],
            'reply_templates' => [],
            'action_steps' => [],
            'prohibited_claims' => ['Do not promise delivery.'],
            'escalation_rules' => [],
            'automation_tier' => 'draft_sensitive',
            'version' => 1,
        ]);
        $article->bindings()->create([
            'consumer' => 'ticket',
            'intent_key' => '*',
            'usage' => 'draft',
        ]);

        return $article;
    }

    private function orderResponse(): array
    {
        return [
            'success' => true,
            'request_id' => 'order-request-1',
            'data' => [
                'order_no' => 'dad20261018-10001',
                'customer' => ['email' => 'customer@example.test'],
                'status' => ['order_status_text' => 'Processing', 'shipping_status_text' => 'Not shipped'],
                'shipping' => ['carrier' => null, 'tracking_no' => null, 'logistics_status' => 'NOT_SHIPPED'],
            ],
            'errors' => [],
        ];
    }

    private function workOrderResponse(): array
    {
        return [
            'success' => true,
            'request_id' => 'work-order-request-1',
            'data' => [
                'items' => [['work_process_text' => 'In production', 'product_status_text' => 'Normal']],
                'pagination' => ['current_page' => 1, 'page_size' => 100, 'total' => 1, 'last_page' => 1],
            ],
            'errors' => [],
        ];
    }
}
