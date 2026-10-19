<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\ConversationAttachment;
use App\Models\ConversationDraft;
use App\Models\ConversationEvent;
use App\Models\DecisionCase;
use App\Models\DecisionNode;
use App\Models\DecisionNodeEvidence;
use App\Services\TicketWork\N8nTicketDraftService;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_list_filter_and_view_tickets(): void
    {
        $administrator = $this->administrator();
        $account = $this->account();
        $ticket = $this->ticket($account);
        $ticket->update(['last_message_at' => now()->subHour()]);
        $assignedTicket = $this->ticket($account, 'ticket-external-1003', '1003');
        $assignedTicket->update([
            'subject' => 'Assigned open ticket',
            'assignment_snapshot' => ['agent' => ['ID' => 'agent-alex', 'name' => 'Alex']],
        ]);
        Conversation::create([
            'channel_account_id' => $account->id,
            'site_key' => 'ellenbridals.com',
            'external_id' => 'ticket-external-1002',
            'external_short_id' => '1002',
            'subject' => 'Different ticket',
            'status' => 'closed',
            'silo' => 'archive',
            'raw_payload' => [],
        ]);
        $event = ConversationEvent::create([
            'conversation_id' => $ticket->id,
            'external_id' => 'event-1',
            'event_type' => 'message',
            'author_type' => 'customer',
            'author_name' => 'Customer',
            'body_text' => 'Please tell me when my dress will arrive.',
            'content_hash' => hash('sha256', 'event-1'),
            'raw_payload' => [],
        ]);
        ConversationAttachment::create([
            'conversation_event_id' => $event->id,
            'external_key' => 'attachment-1',
            'filename' => 'customer-photo.png',
            'mime_type' => 'image/png',
            'size_bytes' => 2048,
            'remote_url' => 'https://cdn.livechat-files.test/customer-photo.png',
            'raw_payload' => [],
        ]);

        $this->actingAs($administrator, 'admin');

        $this->get('/admin/conversations')
            ->assertOk()
            ->assertSee('Wedding dress delivery status')
            ->assertSee('All recent tickets')
            ->assertSee('Tickets to handle')
            ->assertSee('My open tickets')
            ->assertSee('1 hour ago')
            ->assertDontSee('Different ticket');

        $this->get('/admin/conversations?view=to_handle')
            ->assertOk()
            ->assertSee('Wedding dress delivery status')
            ->assertDontSee('Assigned open ticket');

        $this->get('/admin/conversations?view=my_open')
            ->assertOk()
            ->assertSee('Assigned open ticket')
            ->assertDontSee('Wedding dress delivery status');

        $this->get('/admin/conversations?view=all&silo=archive')
            ->assertOk()
            ->assertSee('Different ticket')
            ->assertDontSee('Wedding dress delivery status');

        $this->get('/admin/conversations?site_key=dressafford.com&keyword=delivery')
            ->assertOk()
            ->assertSee('Wedding dress delivery status')
            ->assertDontSee('Different ticket');

        $this->get("/admin/conversations/{$ticket->id}")
            ->assertOk()
            ->assertSee('ticket-external-1001')
            ->assertSee('Please tell me when my dress will arrive.')
            ->assertSee('customer-photo.png')
            ->assertSee('https://cdn.livechat-files.test/customer-photo.png', false)
            ->assertSee('2 KB');
    }

    public function test_administrator_can_save_local_draft_without_external_request(): void
    {
        Http::fake();
        $administrator = $this->administrator();
        $ticket = $this->ticket($this->account());

        $this->actingAs($administrator, 'admin')
            ->from("/admin/conversations/{$ticket->id}")
            ->post("/admin/conversations/{$ticket->id}/draft", [
                'body' => 'Draft reply for review.',
                'is_private' => '1',
                'status_after_send' => 'pending',
            ])
            ->assertRedirect("/admin/conversations/{$ticket->id}");

        $this->get("/admin/conversations/{$ticket->id}")
            ->assertOk()
            ->assertSee('toastr["success"]', false);

        $this->assertDatabaseHas('conversation_drafts', [
            'conversation_id' => $ticket->id,
            'body' => 'Draft reply for review.',
            'is_private' => true,
            'status_after_send' => 'pending',
            'status' => 'draft',
            'created_by' => $administrator->id,
        ]);
        Http::assertNothingSent();
    }

    public function test_administrator_can_queue_ticket_draft_in_shadow_mode(): void
    {
        config([
            'services.n8n.base_url' => 'https://n8n.test',
            'services.n8n.webhook_token' => 'webhook-token',
            'services.n8n.ticket_draft_webhook_path' => '/webhook/ticket-draft-v1',
        ]);
        Http::fake(['https://n8n.test/webhook/ticket-draft-v1' => Http::response(['accepted' => true], 200)]);
        $administrator = $this->administrator();
        $ticket = $this->ticket($this->account());
        ConversationEvent::create([
            'conversation_id' => $ticket->id,
            'external_id' => 'event-ticket-draft',
            'event_type' => 'message',
            'occurred_at' => now(),
            'author_type' => 'client',
            'body_text' => 'Where is order dad20261018-10001?',
            'content_hash' => hash('sha256', 'event-ticket-draft'),
            'raw_payload' => [],
        ]);

        $this->actingAs($administrator, 'admin')
            ->post("/admin/conversations/{$ticket->id}/generate-draft")
            ->assertRedirect();

        $this->assertDatabaseHas('customer_cases', [
            'case_key' => "helpdesk:{$ticket->channel_account_id}:{$ticket->external_id}",
            'case_type' => 'ticket',
        ]);
        $this->assertDatabaseHas('customer_case_sources', [
            'source_type' => 'conversation',
            'source_id' => (string) $ticket->id,
        ]);
        $this->assertDatabaseHas('work_items', [
            'work_type' => 'ticket_draft',
            'status' => 'queued',
            'workflow_key' => N8nTicketDraftService::WORKFLOW_KEY,
            'workflow_version' => N8nTicketDraftService::WORKFLOW_VERSION,
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://n8n.test/webhook/ticket-draft-v1'
            && $request->hasHeader('X-Workflow-Token', 'webhook-token'));

        $this->get("/admin/conversations/{$ticket->id}")
            ->assertOk()
            ->assertSee('shadow 模式')
            ->assertSee('work/ticket-draft-v1');
    }

    public function test_administrator_can_review_generated_draft_but_cannot_accept_a_blocked_one(): void
    {
        $administrator = $this->administrator();
        $ticket = $this->ticket($this->account());
        $draft = ConversationDraft::create([
            'conversation_id' => $ticket->id,
            'body' => 'Initial AI answer.',
            'source' => 'n8n',
            'status' => 'draft',
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);
        $blocked = ConversationDraft::create([
            'conversation_id' => $ticket->id,
            'body' => 'Unsafe answer.',
            'source' => 'n8n',
            'status' => 'blocked',
            'created_by' => $administrator->id,
            'updated_by' => $administrator->id,
        ]);

        $this->actingAs($administrator, 'admin')
            ->post("/admin/conversations/{$ticket->id}/drafts/{$draft->id}/review", [
                'review_outcome' => 'edited',
                'body' => 'Human-edited answer.',
                'review_notes' => 'Adjusted tone.',
            ])->assertRedirect();
        $this->assertDatabaseHas('conversation_drafts', [
            'id' => $draft->id,
            'body' => 'Human-edited answer.',
            'review_outcome' => 'edited',
            'reviewed_by' => $administrator->id,
        ]);

        $this->from("/admin/conversations/{$ticket->id}")
            ->post("/admin/conversations/{$ticket->id}/drafts/{$blocked->id}/review", [
                'review_outcome' => 'accepted_as_is',
                'body' => 'Unsafe answer.',
            ])->assertRedirect("/admin/conversations/{$ticket->id}")
            ->assertSessionHasErrors('review_outcome');
        $this->assertNull($blocked->fresh()->review_outcome);
    }

    public function test_administrator_can_review_case_and_node_independently(): void
    {
        $administrator = $this->administrator();
        $ticket = $this->ticket($this->account());
        $event = ConversationEvent::create([
            'conversation_id' => $ticket->id,
            'external_id' => 'event-review',
            'event_type' => 'message',
            'author_type' => 'client',
            'body_text' => 'Please cancel this order.',
            'content_hash' => hash('sha256', 'event-review'),
            'raw_payload' => [],
        ]);
        ConversationAnalysis::create([
            'conversation_id' => $ticket->id,
            'primary_category' => 'order_change',
            'primary_problem_type' => 'production_cancellation',
            'urgency' => 'normal',
            'analyzer_version' => 'test',
            'analyzed_at' => now(),
        ]);
        $case = DecisionCase::create([
            'conversation_id' => $ticket->id,
            'case_key' => 'helpdesk:'.$ticket->external_id,
            'primary_category' => 'order_change',
            'primary_problem_type' => 'production_cancellation',
            'case_risk' => 'high',
            'case_summary' => 'Cancellation requested after production.',
            'node_count' => 1,
            'review_status' => 'candidate',
            'extractor_version' => 'test',
            'input_hash' => hash('sha256', 'case'),
            'extracted_at' => now(),
        ]);
        $node = DecisionNode::create([
            'decision_case_id' => $case->id,
            'sequence' => 1,
            'problem_type' => 'production_cancellation',
            'scenario_key' => 'in_production',
            'request_summary' => 'Cancel order',
            'observed_decision' => 'conditional',
            'extractor_version' => 'test',
        ]);
        DecisionNodeEvidence::create([
            'decision_node_id' => $node->id,
            'conversation_event_id' => $event->id,
            'evidence_role' => 'request',
            'extractor_version' => 'test',
        ]);

        $this->actingAs($administrator, 'admin');
        $this->get("/admin/conversations/{$ticket->id}")
            ->assertOk()
            ->assertSee('Cancellation requested after production.')
            ->assertSee('Please cancel this order.')
            ->assertSee('href="#event-'.$event->id.'"', false);

        $this->post("/admin/conversations/{$ticket->id}/cases/{$case->id}/review", [
            'review_status' => 'corrected',
            'primary_category' => 'cancellation',
            'primary_problem_type' => 'production_cancellation',
            'case_risk' => 'critical',
            'case_summary' => 'Corrected summary.',
            'review_notes' => 'Verified against the conversation.',
        ])->assertRedirect();
        $this->assertDatabaseHas('decision_cases', [
            'id' => $case->id,
            'review_status' => 'corrected',
            'case_summary' => 'Corrected summary.',
            'reviewed_by' => $administrator->id,
        ]);
        $this->assertDatabaseHas('decision_nodes', ['id' => $node->id, 'review_status' => 'candidate']);

        $this->post("/admin/conversations/{$ticket->id}/nodes/{$node->id}/review", [
            'review_status' => 'confirmed',
            'scenario_key' => 'in_production',
            'request_summary' => 'Cancel an order already in production.',
            'observed_decision' => 'conditional',
            'observed_action' => 'Request production approval.',
            'stated_reason' => 'Production has started.',
            'observed_outcome' => 'Awaiting approval.',
            'review_notes' => 'Evidence checked.',
        ])->assertRedirect();
        $this->assertDatabaseHas('decision_nodes', [
            'id' => $node->id,
            'review_status' => 'confirmed',
            'request_summary' => 'Cancel an order already in production.',
            'reviewed_by' => $administrator->id,
        ]);
    }

    public function test_review_routes_reject_case_and_node_from_another_ticket(): void
    {
        $administrator = $this->administrator();
        $account = $this->account();
        $ticket = $this->ticket($account);
        $otherTicket = $this->ticket($account, 'ticket-external-2002', '2002');
        $case = DecisionCase::create([
            'conversation_id' => $otherTicket->id,
            'case_key' => 'helpdesk:'.$otherTicket->external_id,
            'review_status' => 'candidate',
            'extractor_version' => 'test',
            'input_hash' => hash('sha256', 'other-case'),
            'extracted_at' => now(),
        ]);
        $node = DecisionNode::create([
            'decision_case_id' => $case->id,
            'sequence' => 1,
            'request_summary' => 'Other ticket request',
            'observed_decision' => 'unknown',
            'extractor_version' => 'test',
        ]);

        $this->actingAs($administrator, 'admin');
        $this->post("/admin/conversations/{$ticket->id}/cases/{$case->id}/review", [])->assertNotFound();
        $this->post("/admin/conversations/{$ticket->id}/nodes/{$node->id}/review", [])->assertNotFound();
    }

    public function test_guest_cannot_access_ticket_workbench(): void
    {
        $this->get('/admin/conversations')->assertRedirect('/admin/auth/login');
    }

    public function test_ticket_menu_is_created_by_migrations(): void
    {
        $this->assertDatabaseHas(config('admin.database.menu_table'), [
            'title' => 'Tickets',
            'uri' => 'conversations',
        ]);
    }

    private function administrator(): Administrator
    {
        $administrator = Administrator::create([
            'username' => 'admin-test',
            'password' => Hash::make('secret'),
            'name' => 'Admin Test',
        ]);
        $role = Role::where('slug', 'administrator')->firstOrFail();
        $administrator->roles()->attach($role);

        return $administrator;
    }

    private function account(): ChannelAccount
    {
        return ChannelAccount::create([
            'channel' => 'helpdesk',
            'external_account_id' => 'account-1',
            'display_name' => 'HelpDesk Main',
        ]);
    }

    private function ticket(
        ChannelAccount $account,
        string $externalId = 'ticket-external-1001',
        string $shortId = '1001',
    ): Conversation {
        return Conversation::create([
            'channel_account_id' => $account->id,
            'site_key' => 'dressafford.com',
            'external_id' => $externalId,
            'external_short_id' => $shortId,
            'subject' => 'Wedding dress delivery status',
            'status' => 'open',
            'priority' => 1,
            'silo' => 'tickets',
            'requester_email' => 'customer@example.com',
            'requester_snapshot' => [],
            'assignment_snapshot' => [],
            'raw_payload' => [],
        ]);
    }
}
