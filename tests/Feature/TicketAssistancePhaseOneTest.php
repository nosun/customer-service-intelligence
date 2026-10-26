<?php

namespace Tests\Feature;

use App\Jobs\DispatchTicketAssistance;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationDraft;
use App\Models\ConversationEvent;
use App\Models\TicketAssistanceFeedback;
use App\Models\WorkItem;
use App\Services\TicketWork\N8nTicketDraftService;
use App\Services\TicketWork\TicketAssistanceDispatcher;
use App\Services\TicketWork\TicketAssistanceView;
use App\Services\TicketWork\TicketOrderWorkbench;
use App\Services\TicketWork\TicketWorkItemManager;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketAssistancePhaseOneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['ticket-assistance.enabled' => false, 'weknora.publish_enabled' => false, 'weknora.retrieval_enabled' => false]);
    }

    public function test_scan_reuses_completed_input_across_operators_and_new_message_creates_one_task(): void
    {
        $ticket = $this->ticket();
        $dispatcher = app(TicketAssistanceDispatcher::class);
        $first = $dispatcher->dispatch($ticket, 1);
        $first->update(['status' => 'completed', 'result' => ['draft_status' => 'no_reply']]);
        $same = $dispatcher->dispatch($ticket, 22);
        $this->assertSame($first->id, $same->id);
        $this->assertFalse($same->wasRecentlyCreated);
        $this->message($ticket, 'new-event', 'A new question');
        $next = $dispatcher->dispatch($ticket, 22);
        $this->assertNotSame($first->id, $next->id);
        $this->assertSame($next->id, $dispatcher->dispatch($ticket, 1)->id);
        Queue::assertPushed(DispatchTicketAssistance::class, 2);
        Http::assertNothingSent();
    }

    public function test_failed_same_input_is_not_recreated_by_scans_but_manual_retry_is_available(): void
    {
        $ticket = $this->ticket();
        $first = app(TicketAssistanceDispatcher::class)->dispatch($ticket, 1);
        $first->update(['status' => 'failed']);
        $this->assertSame($first->id, app(TicketAssistanceDispatcher::class)->dispatch($ticket, 1)->id);
        $manual = app(TicketWorkItemManager::class)->create($ticket, 1, N8nTicketDraftService::INVESTIGATION_KEY, N8nTicketDraftService::INVESTIGATION_VERSION);
        $this->assertNotSame($first->id, $manual->id);
    }

    public function test_command_is_opt_in_and_limits_new_work_without_starving_after_completed_tickets(): void
    {
        $actor = $this->administrator();
        $first = $this->ticket();
        $second = $this->ticket();
        $this->artisan('tickets:assist')->assertSuccessful();
        $this->assertSame(0, WorkItem::count());
        config(['ticket-assistance.enabled' => true, 'ticket-assistance.actor_id' => $actor->id,
            'services.n8n.base_url' => 'https://n8n.test', 'services.n8n.webhook_token' => 'test']);
        $this->artisan('tickets:assist', ['--limit' => 1])->expectsOutput('queued=1 failed=0')->assertSuccessful();
        WorkItem::first()->update(['status' => 'completed']);
        $this->artisan('tickets:assist', ['--limit' => 1])->expectsOutput('queued=1 failed=0')->assertSuccessful();
        $this->assertSame(2, WorkItem::count());
        $this->artisan('tickets:assist')->expectsOutput('queued=0 failed=0')->assertSuccessful();
    }

    public function test_closed_tickets_are_never_dispatched(): void
    {
        $ticket = $this->ticket();
        $ticket->update(['status' => 'closed']);
        $this->expectException(\RuntimeException::class);
        app(TicketAssistanceDispatcher::class)->dispatch($ticket, 1);
    }

    public function test_feedback_changes_only_current_ticket_and_preserves_the_original_result(): void
    {
        $actor = $this->administrator();
        $ticket = $this->ticket();
        $other = $this->ticket();
        $item = app(TicketAssistanceDispatcher::class)->dispatch($ticket, $actor->id);
        $original = ['reply_plan' => ['case_progress' => ['summary' => 'Original summary']], 'draft_status' => 'draft'];
        $item->update(['status' => 'completed', 'result' => $original]);
        $this->actingAs($actor, 'admin')->post('/admin/conversations/'.$ticket->id.'/assistance-feedback', [
            'work_item_id' => $item->id, 'target' => 'advice', 'kind' => 'decision',
            'body' => 'Approve this exception for the current customer only.', 'knowledge_candidate' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($original, $item->fresh()->result);
        $this->assertSame($original, TicketAssistanceFeedback::first()->snapshot['result']);
        $this->assertStringContainsString('不代表已经执行', $ticket->fresh()->draftGuidance()['text']);
        $this->assertNull($other->fresh()->draftGuidance());
        $this->assertFalse(app(TicketAssistanceView::class)->current($ticket->fresh(), $item->fresh()));
        $this->get('/admin/conversations/'.$ticket->id)->assertOk()->assertSee('1. 情况分析')->assertSee('2. 处理意见')->assertSee('3. 邮件草稿')->assertSee('结果待更新');
        $this->get('/admin/conversations')->assertOk()->assertSee('结果待更新');
    }

    public function test_feedback_cannot_target_another_ticket_run(): void
    {
        $actor = $this->administrator();
        $ticket = $this->ticket();
        $item = app(TicketAssistanceDispatcher::class)->dispatch($this->ticket(), $actor->id);
        $this->actingAs($actor, 'admin')->post('/admin/conversations/'.$ticket->id.'/assistance-feedback', [
            'work_item_id' => $item->id, 'target' => 'analysis', 'kind' => 'correction', 'body' => 'Wrong target',
        ])->assertSessionHasErrors('work_item_id');
        $this->assertSame(0, TicketAssistanceFeedback::count());
    }

    public function test_feedback_regeneration_uses_v2_and_keeps_feedback_when_closed(): void
    {
        $actor = $this->administrator();
        $ticket = $this->ticket();
        $this->actingAs($actor, 'admin')->post('/admin/conversations/'.$ticket->id.'/assistance-feedback', [
            'target' => 'analysis', 'kind' => 'fact', 'body' => 'Factory confirms progress today.', 'regenerate' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(N8nTicketDraftService::INVESTIGATION_KEY, WorkItem::first()->workflow_key);
        $ticket->update(['status' => 'closed']);
        $this->post('/admin/conversations/'.$ticket->id.'/assistance-feedback', [
            'target' => 'analysis', 'kind' => 'correction', 'body' => 'Resolved.', 'regenerate' => 1,
        ])->assertSessionHasErrors('feedback');
        $this->assertSame(2, TicketAssistanceFeedback::count());
        $this->assertSame(1, WorkItem::count());
    }

    public function test_guidance_replacement_and_manual_draft_edits_keep_before_and_after(): void
    {
        $actor = $this->administrator();
        $ticket = $this->ticket();
        $this->actingAs($actor, 'admin');
        foreach (['First source.', 'Updated source.'] as $body) {
            $this->post('/admin/conversations/'.$ticket->id.'/guidance', ['draft_guidance' => $body])->assertRedirect();
        }
        $history = TicketAssistanceFeedback::where('target', 'guidance')->latest('id')->first();
        $this->assertSame(['before' => 'First source.', 'after' => 'Updated source.'], $history->snapshot);
        foreach (['Original manual email.', 'Edited manual email.'] as $body) {
            $this->post('/admin/conversations/'.$ticket->id.'/draft', ['body' => $body])->assertRedirect();
        }
        $audit = TicketAssistanceFeedback::where('kind', 'manual_save')->latest('id')->first();
        $this->assertSame('Original manual email.', $audit->snapshot['before']);
        $this->assertSame('Edited manual email.', $audit->snapshot['after']);
        $this->assertSame(1, ConversationDraft::where('source', 'manual')->count());
    }

    public function test_no_reply_keeps_analysis_advice_and_does_not_reuse_a_previous_email(): void
    {
        $ticket = $this->ticket();
        $item = app(TicketAssistanceDispatcher::class)->dispatch($ticket, 1);
        $item->update(['status' => 'completed', 'result' => [
            'draft_status' => 'no_reply', 'reply_plan' => ['case_progress' => ['summary' => 'Waiting for customer documents'],
                'response_decision' => ['mode' => 'no_reply', 'reason' => 'Already acknowledged'], 'answer_units' => [], 'internal_followups' => ['Check on Friday']],
        ]]);
        $result = app(TicketAssistanceView::class)->present($ticket, $item->fresh());
        $this->assertSame('no_reply', $result['status']);
        $this->assertSame('Waiting for customer documents', $result['analysis']['summary']);
        $this->assertSame(['Check on Friday'], $result['advice']['followups']);
        $this->assertArrayNotHasKey('body', $result);
    }

    public function test_reaper_respects_a_valid_thirty_minute_v2_lease(): void
    {
        $item = app(TicketAssistanceDispatcher::class)->dispatch($this->ticket(), 1);
        $item->update(['status' => 'running', 'lease_owner' => 'worker', 'lease_expires_at' => now()->addMinutes(15)]);
        $item->timestamps = false;
        $item->update(['updated_at' => now()->subMinutes(15)]);
        $this->artisan('tickets:reap-draft-work-items')->expectsOutput('retried=0 failed=0')->assertSuccessful();
        $this->assertSame('running', $item->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_new_fact_after_agent_reply_can_trigger_followup_without_new_customer_email(): void
    {
        $actor = $this->administrator();
        $ticket = $this->ticket();
        $this->message($ticket, 'agent-followup', 'We are checking this.');
        $ticket->events()->where('external_id', 'agent-followup')->update(['author_type' => 'agent', 'occurred_at' => now()->subMinute()]);
        $ticket->events()->where('external_id', '!=', 'agent-followup')->update(['occurred_at' => now()->subMinutes(2)]);
        $this->actingAs($actor, 'admin')->post('/admin/conversations/'.$ticket->id.'/assistance-feedback', [
            'target' => 'analysis', 'kind' => 'fact', 'body' => 'Factory confirmed the item is ready today.', 'regenerate' => 1,
        ])->assertSessionHasNoErrors();
        $item = WorkItem::firstOrFail();
        $this->assertSame('agent-followup', ConversationEvent::find($item->input_snapshot['trigger_event_id'])->external_id);
        $this->assertStringContainsString('Factory confirmed', $item->input_snapshot['operator_guidance']['text']);
    }

    public function test_ai_email_edits_keep_original_body_and_each_review_revision(): void
    {
        $actor = $this->administrator();
        $ticket = $this->ticket();
        $item = app(TicketAssistanceDispatcher::class)->dispatch($ticket, $actor->id);
        $item->update(['status' => 'completed', 'result' => ['draft_status' => 'draft']]);
        $draft = ConversationDraft::create(['conversation_id' => $ticket->id, 'work_item_id' => $item->id,
            'source' => 'n8n', 'status' => 'draft', 'body' => 'Original model email.', 'created_by' => $actor->id, 'updated_by' => $actor->id,
            'metadata' => ['workflow_key' => N8nTicketDraftService::INVESTIGATION_KEY,
                'workbench_input_hash' => app(TicketOrderWorkbench::class)->inputHash($ticket), 'requires_order' => false]]);
        $this->actingAs($actor, 'admin');
        foreach (['First edited email.', 'Second edited email.'] as $body) {
            $this->post('/admin/conversations/'.$ticket->id.'/drafts/'.$draft->id.'/review', [
                'review_outcome' => 'edited', 'body' => $body, 'review_notes' => 'Correct the wording.',
            ])->assertSessionHasNoErrors();
        }
        $history = TicketAssistanceFeedback::where('target', 'draft')->orderBy('id')->get();
        $this->assertSame('Original model email.', $history[0]->snapshot['before']);
        $this->assertSame('First edited email.', $history[1]->snapshot['before']);
        $this->assertSame('Second edited email.', $draft->fresh()->body);
        $this->assertSame('draft', $item->fresh()->result['draft_status']);
        Http::assertNothingSent();
    }

    private function administrator(): Administrator
    {
        $admin = Administrator::create(['username' => 'phase-one', 'password' => bcrypt('test'), 'name' => 'Phase One']);
        $role = Role::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $admin->roles()->sync([$role->id]);

        return $admin;
    }

    private function ticket(): Conversation
    {
        $account = ChannelAccount::firstOrCreate(['channel' => 'helpdesk', 'external_account_id' => 'phase-one'], ['display_name' => 'Test']);
        $ticket = Conversation::create(['channel_account_id' => $account->id, 'external_id' => (string) Str::uuid(),
            'site_key' => 'test.example', 'subject' => 'Delivery inquiry', 'status' => 'open', 'silo' => 'tickets', 'raw_payload' => []]);
        $this->message($ticket, (string) Str::uuid(), 'Where is my package?');

        return $ticket;
    }

    private function message(Conversation $ticket, string $key, string $text): void
    {
        ConversationEvent::create(['conversation_id' => $ticket->id, 'external_id' => $key, 'event_type' => 'message',
            'author_type' => 'customer', 'is_private' => false, 'body_text' => $text, 'content_hash' => hash('sha256', $text.$key), 'occurred_at' => now(), 'raw_payload' => []]);
    }
}
