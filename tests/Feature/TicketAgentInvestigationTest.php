<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\KnowledgeArticle;
use App\Services\Agents\TicketInvestigationInput;
use App\Services\Agents\TicketInvestigationWorker;
use App\Services\Work\FactResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TicketAgentInvestigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.console_new.base_url' => 'https://console.test/api/admin', 'services.console_new.token' => 'test-token']);
        Http::preventStrayRequests();
    }

    public function test_input_reuses_real_context_fact_and_policy_services_without_business_writes(): void
    {
        $ticket = $this->ticket();
        $this->policy('agent_logistics', 'verified');
        $this->policy('agent_observed', 'observed');
        $this->policy('agent_other_site', 'verified', 'other-site.com');
        $this->policy('agent_refund', 'verified', null, 'refund_review');
        $this->fakeOrder();
        $input = app(TicketInvestigationInput::class)->capture($ticket);
        $this->assertSame([], $input['safety_flags']);
        $keys = array_column($input['policies'], 'key');
        $this->assertContains('agent_logistics', $keys);
        foreach (['agent_observed', 'agent_other_site', 'agent_refund'] as $excluded) {
            $this->assertNotContains($excluded, $keys);
        }
        $this->assertContains('shipment.logistics_status', array_column($input['facts'], 'key'));
        $json = json_encode($input, JSON_THROW_ON_ERROR);
        foreach (['customer@example.test', 'Melissa', 'dad20261018-10001', 'PRIVATE FACTORY NOTE', 'SECRET-TRACKING-123', 'secret-address'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertStringContainsString('[EMAIL]', $json);
        $this->assertStringContainsString('[CUSTOMER_NAME]', $json);
        $this->assertDatabaseCount('conversation_drafts', 0);
        $this->assertDatabaseCount('work_items', 0);
        $this->assertDatabaseCount('business_context_snapshots', 0);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_identity_mismatch_missing_identity_and_lookup_failure_never_expose_order_facts(): void
    {
        foreach (['other@example.test', '', 'lookup_failure'] as $identity) {
            $this->fakeOrder($identity);
            $input = app(TicketInvestigationInput::class)->capture($this->ticket());
            $this->assertSame([], $input['facts']);
            $this->assertSame(['order_identity_or_lookup_unverified'], $input['safety_flags']);
        }
    }

    public function test_fact_filter_rejects_private_restricted_unknown_stale_future_and_missing_freshness(): void
    {
        $valid = (new FactResult('shipment.carrier', 'Example', 'test', now(), 'internal', now()->addMinutes(10)))->toArray();
        $bad = [
            [...$valid, 'sensitivity' => 'private'], [...$valid, 'sensitivity' => 'restricted'],
            [...$valid, 'key' => 'customer.email'], [...$valid, 'shareable_with_ai' => false],
            [...$valid, 'fresh_until' => now()->subMinute()->toIso8601String()],
            [...$valid, 'captured_at' => now()->addMinute()->toIso8601String()],
            [...$valid, 'fresh_until' => null], [...$valid, 'value' => ['email' => 'secret']],
        ];
        $this->assertSame([$valid], app(TicketInvestigationInput::class)->shareableFacts([$valid, ...$bad]));
    }

    public function test_input_changes_and_policy_version_changes_invalidate_a_run(): void
    {
        $ticket = $this->ticket();
        $policy = $this->policy('agent_versioned', 'verified');
        $this->fakeOrder();
        $service = app(TicketInvestigationInput::class);
        $input = $service->capture($ticket);
        $policy->update(['version' => 2]);
        try {
            $service->assertCurrent($ticket, $input['source_input_hash']);
            $this->fail('Changed knowledge must invalidate the input.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('changed during investigation', $exception->getMessage());
        }
        $input = $service->capture($ticket);
        $ticket->update(['draft_guidance' => 'New investigation guidance']);
        $this->expectExceptionMessage('changed during investigation');
        $service->assertCurrent($ticket, $input['source_input_hash']);
    }

    public function test_mutually_exclusive_command_inputs_fail_without_calling_worker(): void
    {
        $this->mock(TicketInvestigationWorker::class)->shouldNotReceive('run');
        $this->artisan('tickets:agent-investigate')->assertFailed();
        $this->artisan('tickets:agent-investigate', ['--conversation' => '1', '--fixture' => 'unused.json'])->assertFailed();
    }

    public function test_replay_conversation_is_rejected_without_order_query(): void
    {
        $ticket = $this->ticket();
        $ticket->update(['source_type' => 'replay']);
        $this->expectExceptionMessage('non-replay Ticket');
        try {
            app(TicketInvestigationInput::class)->capture($ticket);
        } finally {
            Http::assertNothingSent();
        }
    }

    private function ticket(): Conversation
    {
        $account = ChannelAccount::firstOrCreate(['channel' => 'helpdesk', 'external_account_id' => 'agent-test']);
        $ticket = Conversation::create([
            'channel_account_id' => $account->id, 'external_id' => uniqid('agent-', true),
            'source_type' => 'helpdesk', 'silo' => 'tickets', 'status' => 'open', 'site_key' => 'dressafford.com',
            'requester_email' => 'customer@example.test', 'requester_snapshot' => ['name' => 'Melissa'],
            'subject' => 'Missing order dad20261018-10001', 'linked_order_number' => 'dad20261018-10001', 'raw_payload' => [],
        ]);
        foreach ([['client', 'Hi Melissa, tracking says delivered but I did not receive it. customer@example.test', false], ['agent', 'PRIVATE FACTORY NOTE', true]] as [$author, $text, $private]) {
            ConversationEvent::create([
                'conversation_id' => $ticket->id, 'external_id' => uniqid('agent-event-', true),
                'event_type' => 'message', 'occurred_at' => now()->subMinute(), 'author_type' => $author,
                'is_private' => $private, 'body_text' => $text, 'content_hash' => hash('sha256', $text), 'raw_payload' => [],
            ]);
        }

        return $ticket;
    }

    private function policy(string $key, string $status, ?string $site = null, string $intent = 'logistics_tracking_delivery'): KnowledgeArticle
    {
        $article = KnowledgeArticle::create([
            'key' => $key, 'title' => $key, 'knowledge_type' => 'process', 'status' => $status,
            'site_key' => $site, 'applicability' => 'Delivered but not received', 'version' => 1,
            'policy_rules' => ['Obtain proof of delivery.'], 'automation_tier' => 'draft_sensitive',
        ]);
        $article->bindings()->create(['consumer' => 'ticket', 'intent_key' => $intent, 'usage' => 'draft', 'enabled' => true]);

        return $article;
    }

    private function fakeOrder(string $email = 'customer@example.test'): void
    {
        Http::fake(['https://console.test/api/admin/orders/*' => $email === 'lookup_failure'
            ? Http::response(['message' => 'failure with secret-address'], 500)
            : Http::response(['success' => true, 'data' => [
                'customer' => ['email' => $email, 'address' => 'secret-address'],
                'status' => ['order_status_text' => 'Completed', 'shipping_status_text' => 'Shipped'],
                'shipping' => ['carrier' => 'Example', 'tracking_no' => 'SECRET-TRACKING-123', 'logistics_status' => 'Delivered'],
            ]])]);
    }
}
