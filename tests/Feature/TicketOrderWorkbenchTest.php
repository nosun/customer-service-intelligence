<?php

namespace Tests\Feature;

use App\Jobs\SyncHelpDeskTickets;
use App\Models\ChannelAccount;
use App\Models\CommerceSite;
use App\Models\Conversation;
use App\Models\ConversationDraft;
use App\Models\ConversationEvent;
use App\Models\KnowledgeArticle;
use App\Models\WorkItem;
use App\Services\Knowledge\KnowledgeResolver;
use App\Services\N8n\N8nTicketDraftWorkflowClient;
use App\Services\TicketWork\N8nTicketDraftService;
use App\Services\TicketWork\TicketDraftInput;
use App\Services\TicketWork\TicketFactResolver;
use App\Services\TicketWork\TicketOrderActionService;
use App\Services\TicketWork\TicketOrderWorkbench;
use App\Services\TicketWork\TicketWorkItemManager;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Permission;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class TicketOrderWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.console_new.base_url' => 'https://console.test/api/admin', 'services.console_new.token' => 'test-token']);
        Http::preventStrayRequests();
    }

    public function test_inspection_reads_all_work_order_pages_and_separates_refund_advice(): void
    {
        $ticket = $this->ticket();
        $this->fake($this->order(), 2);
        $snapshot = app(TicketOrderWorkbench::class)->inspect($ticket);
        $this->assertSame('found', $snapshot->lookup_status);
        $this->assertCount(2, $snapshot->summary['work_orders']);
        $this->assertSame('dad20261021-38183', $ticket->fresh()->linked_order_number);
        $this->assertSame('213.96', data_get($snapshot->summary, 'assessment.refund.unallocated_paid_amount'));
        $this->assertTrue(app(TicketOrderWorkbench::class)->isFresh($ticket, $snapshot));
        $this->assertStringNotContainsString('customer@example.test', json_encode($snapshot->summary));
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
        $this->actingAs($this->admin(), 'admin')->get('/admin/conversations/'.$ticket->id)->assertOk()->assertSee('退款核查（仅建议）')->assertSee('当前 Ticket 回复指南');
    }

    public function test_identity_and_site_mismatch_fail_closed_even_with_explicit_order_number(): void
    {
        $ticket = $this->ticket();
        foreach ([['customer' => ['email' => 'other@example.test']], ['site' => ['url' => 'https://dressafford.com.evil.test']]] as $override) {
            $this->fake(array_replace($this->order(), $override));
            $snapshot = app(TicketOrderWorkbench::class)->inspect($ticket, 'dad20261021-38183');
            $this->assertSame('failed', $snapshot->lookup_status);
            $this->assertArrayNotHasKey('order', $snapshot->summary);
        }
        $this->assertNull($ticket->fresh()->linked_order_number);
    }

    public function test_planner_receives_current_inspection_facts_without_customer_identifiers(): void
    {
        $ticket = $this->ticket();
        $this->fake($this->order());
        app(TicketOrderWorkbench::class)->inspect($ticket);
        $item = app(TicketWorkItemManager::class)->create($ticket, 1, N8nTicketDraftService::WORKFLOW_KEY, '1.0.0');
        $context = app(N8nTicketDraftService::class)->start($item, 'planner-facts');
        $this->assertSame('未发货', collect($context['order_assessment']['facts'])->firstWhere('key', 'order.shipping_status')['value']);
        $this->assertStringNotContainsString('customer@example.test', json_encode($context['order_assessment']));
        $this->assertStringNotContainsString('dad20261021-38183', json_encode($context['order_assessment']));
        $this->travel(11)->minutes();
        $this->assertNull(app(TicketFactResolver::class)->planningContext($ticket));
    }

    public function test_missing_ticket_site_is_resolved_only_from_a_unique_configured_order_host(): void
    {
        $ticket = $this->ticket();
        $ticket->update(['site_key' => null]);
        CommerceSite::create(['name' => 'Dressafford', 'api_token' => 'test-token', 'admin_api_url' => 'https://dressafford.test/api', 'site_key' => 'dressafford', 'public_base_url' => 'https://www.dressafford.com', 'enabled' => true]);
        $this->fake($this->order());
        $snapshot = app(TicketOrderWorkbench::class)->inspect($ticket);
        $this->assertSame('found', $snapshot->lookup_status);
        $this->assertSame('dressafford.com', $ticket->fresh()->site_key);
        $this->assertTrue(app(TicketOrderWorkbench::class)->isFresh($ticket, $snapshot));

        $ticket->update(['site_key' => null]);
        $order = $this->order();
        $order['customer']['email'] = 'other@example.test';
        $this->fake($order);
        $this->assertSame('failed', app(TicketOrderWorkbench::class)->inspect($ticket)->lookup_status);
        $this->assertNull($ticket->fresh()->site_key);

        $order = $this->order();
        $order['site']['url'] = 'https://dressafford.com.evil.test';
        $this->fake($order);
        $this->assertSame('failed', app(TicketOrderWorkbench::class)->inspect($ticket)->lookup_status);
        $this->assertNull($ticket->fresh()->site_key);
    }

    public function test_generation_saves_current_guidance_and_refreshes_order_before_capturing_input(): void
    {
        $ticket = $this->ticket();
        $this->fake($this->order());
        app(TicketOrderWorkbench::class)->inspect($ticket);
        $this->mock(N8nTicketDraftWorkflowClient::class)->shouldReceive('trigger')->once();
        $this->actingAs($this->admin(), 'admin')->post('/admin/conversations/'.$ticket->id.'/generate-draft', [
            'inspect_order' => '1', 'draft_guidance' => 'Factory confirms production has not started.',
        ])->assertRedirect('/admin/conversations/'.$ticket->id.'#reply-draft');
        $ticket->refresh();
        $this->assertSame('Factory confirms production has not started.', $ticket->draft_guidance);
        $this->assertTrue(app(TicketOrderWorkbench::class)->isFresh($ticket, $ticket->latestOrderContext));
        $this->assertDatabaseHas('work_items', ['status' => 'queued']);
        $item = WorkItem::latest('id')->firstOrFail();
        $this->assertSame($ticket->draft_guidance, data_get($item->input_snapshot, 'operator_guidance.text'));
        $this->assertSame($ticket->latestOrderContext->id, data_get($item->input_snapshot, 'order_context.id'));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_candidate_generation_routes_presales_without_forcing_order_inspection(): void
    {
        config(['services.n8n.ticket_investigation_enabled' => true]);
        $ticket = $this->ticket();
        $ticket->events()->update(['body_text' => 'What fabric is this dress made from?']);
        $this->mock(N8nTicketDraftWorkflowClient::class)->shouldReceive('trigger')->once()->withArgs(fn ($item) => $item->workflow_key === N8nTicketDraftService::INVESTIGATION_KEY);
        $this->actingAs($this->admin(), 'admin')->post('/admin/conversations/'.$ticket->id.'/generate-draft', [
            'inspect_order' => '1', 'draft_guidance' => 'Answer the product consultation using verified knowledge.',
        ])->assertRedirect('/admin/conversations/'.$ticket->id.'#reply-draft');
        Http::assertNothingSent();
        $this->assertDatabaseHas('work_items', ['workflow_key' => N8nTicketDraftService::INVESTIGATION_KEY, 'workflow_version' => '2.0.0', 'status' => 'queued']);
    }

    public function test_identical_order_refresh_preserves_draft_fingerprint_but_changed_facts_do_not(): void
    {
        $ticket = $this->ticket();
        $this->fake($this->order());
        $workbench = app(TicketOrderWorkbench::class);
        $first = $workbench->inspect($ticket);
        $input = app(TicketDraftInput::class);
        $before = $input->capture($ticket, 1, N8nTicketDraftService::INVESTIGATION_KEY, '2.0.0');
        $second = $workbench->inspect($ticket);
        $after = $input->capture($ticket, 1, N8nTicketDraftService::INVESTIGATION_KEY, '2.0.0');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($before['hash'], $after['hash']);
        $changed = $this->order();
        $changed['status']['order_status'] = -1;
        $this->fake($changed);
        $workbench->inspect($ticket);
        $this->assertNotSame($before['hash'], $input->capture($ticket, 1, N8nTicketDraftService::INVESTIGATION_KEY, '2.0.0')['hash']);
    }

    public function test_failed_inspection_stops_generation_before_starting_a_workflow(): void
    {
        $ticket = $this->ticket();
        $order = $this->order();
        $order['customer']['email'] = 'other@example.test';
        $this->fake($order);
        $this->mock(N8nTicketDraftWorkflowClient::class)->shouldNotReceive('trigger');
        $this->actingAs($this->admin(), 'admin')->post('/admin/conversations/'.$ticket->id.'/generate-draft', [
            'inspect_order' => '1', 'draft_guidance' => 'Please check cancellation.',
        ])->assertRedirect('/admin/conversations/'.$ticket->id.'#order-workbench');
        $this->assertDatabaseCount('work_items', 0);
        $this->assertSame('failed', $ticket->latestOrderContext->lookup_status);
    }

    public function test_multiple_email_orders_require_explicit_selection_and_failed_pagination_is_not_complete(): void
    {
        $ticket = $this->ticket();
        $ticket->events()->update(['body_text' => 'Please cancel my order.']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['items' => [['order_no' => 'dad20261021-38183'], ['order_no' => 'dad20261020-38176']], 'pagination' => ['current_page' => 1, 'last_page' => 1, 'total' => 2]]])]);
        $s = app(TicketOrderWorkbench::class)->inspect($ticket);
        $this->assertSame('failed', $s->lookup_status);
        $this->assertCount(2, $s->summary['order_candidates']);
        $this->assertNull($ticket->fresh()->linked_order_number);
        $ticket->events()->update(['body_text' => 'Cancel dad20261021-38183']);
        $this->fake($this->order(), 51);
        $s = app(TicketOrderWorkbench::class)->inspect($ticket);
        $this->assertSame('failed', $s->lookup_status);
    }

    public function test_guidance_changes_invalidate_draft_input_but_keep_identity_bound_order_facts(): void
    {
        $ticket = $this->ticket();
        $this->fake($this->order());
        $snapshot = app(TicketOrderWorkbench::class)->inspect($ticket);
        $before = app(TicketDraftInput::class)->capture($ticket, 1, 'work/ticket-draft-v1', '1.0.0');
        $this->actingAs($this->admin(), 'admin')->post('/admin/conversations/'.$ticket->id.'/guidance', ['draft_guidance' => 'Factory confirmed production has not started.'])->assertRedirect();
        $ticket->refresh();
        $after = app(TicketDraftInput::class)->capture($ticket, 1, 'work/ticket-draft-v1', '1.0.0');
        $this->assertNotSame($before['hash'], $after['hash']);
        $this->assertSame('current_ticket_only', $after['snapshot']['operator_guidance']['scope']);
        $this->assertTrue(app(TicketOrderWorkbench::class)->isFresh($ticket, $snapshot));
    }

    public function test_existing_sync_command_and_manual_button_share_lock_and_do_not_send_helpdesk_writes(): void
    {
        $this->ticket();
        $lock = Cache::lock('helpdesk:incremental-sync', 60);
        $lock->get();
        $this->artisan('helpdesk:sync-incremental')->assertSuccessful();
        Http::assertNothingSent();
        $lock->release();
        Queue::fake();
        $this->actingAs($this->admin(), 'admin')->post('/admin/conversations/sync')->assertRedirect();
        $this->post('/admin/conversations/sync')->assertRedirect();
        Queue::assertPushed(SyncHelpDeskTickets::class, 1);
    }

    public function test_cancellation_preview_is_deduplicated_and_execution_is_disabled_by_default(): void
    {
        $ticket = $this->ticket();
        $this->fake($this->order());
        $policy = $this->policy('production_cancellation');
        app(TicketOrderWorkbench::class)->inspect($ticket);
        $actions = app(TicketOrderActionService::class);
        $proposal = $actions->preview($ticket, 'cancel_order', null, null, $policy->id, 'Customer explicitly requested full cancellation; factory state and policy checked.', 1);
        $again = $actions->preview($ticket, 'cancel_order', null, null, $policy->id, 'Customer explicitly requested full cancellation; factory state and policy checked.', 1);
        $this->assertSame($proposal->id, $again->id);
        $this->assertStringNotContainsString('preview-secret', json_encode($proposal->evidence));
        try {
            $actions->execute($ticket, $proposal, 1);
            $this->fail('Writes must remain disabled until source guards are validated.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('尚未启用', $e->getMessage());
        }
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['preview'] === true && $r['send_email'] === 0);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && $r['preview'] !== true);
        $this->actingAs($this->admin(), 'admin')->get('/admin/conversations/'.$ticket->id)->assertOk()->assertDontSee('preview-secret')->assertSee('执行暂未启用');
    }

    public function test_expired_or_changed_confirmation_cannot_write_and_timeout_can_reconcile_without_retry(): void
    {
        $ticket = $this->ticket();
        $this->fake($this->order());
        $policy = $this->policy('production_cancellation');
        app(TicketOrderWorkbench::class)->inspect($ticket);
        $actions = app(TicketOrderActionService::class);
        $proposal = $actions->preview($ticket, 'cancel_order', null, null, $policy->id, 'Confirmed cancellation', 1);
        config(['services.console_new.ticket_actions_enabled' => true]);
        $ticket->update(['draft_guidance' => 'New factory information']);
        $this->assertSame('stale', $actions->execute($ticket, $proposal, 1)->status);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && $r['preview'] === false);
        $proposal->update(['status' => 'uncertain']);
        $order = $this->order();
        $order['status']['order_status'] = -1;
        $order['items'][0]['status'] = -1;
        $this->fake($order, cancelledWork: true);
        $this->assertSame('succeeded', $actions->reconcile($ticket, $proposal, 9)->status);
        $this->assertSame(9, $proposal->fresh()->execution_result['verified_by']);
        $this->assertFalse($proposal->fresh()->execution_result['refund_executed']);
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && $r['preview'] === false);
    }

    public function test_unknown_refund_status_or_currency_does_not_create_a_refund_amount_recommendation(): void
    {
        $ticket = $this->ticket();
        $order = $this->order();
        $order['refunds'] = [['id' => 1, 'amount' => '100.00', 'currency' => 'EUR', 'refund_status' => 1]];
        $this->fake($order);
        $s = app(TicketOrderWorkbench::class)->inspect($ticket);
        $this->assertNull(data_get($s->summary, 'assessment.refund.unallocated_paid_amount'));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_missing_size_chart_and_w_suffix_ambiguity_do_not_preview_item_update(): void
    {
        $ticket = $this->ticket();
        $order = $this->order();
        $order['items'][0]['size'] = '18W';
        $this->fake($order);
        $policy = $this->policy('standard_size_change');
        app(TicketOrderWorkbench::class)->inspect($ticket);
        try {
            app(TicketOrderActionService::class)->preview($ticket, 'standard_size_change', 1, '20', $policy->id, 'Change size', 1);
            $this->fail('Ambiguous W change must not proceed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('W 系列', $e->getMessage());
        }
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_standard_size_preview_checks_live_chart_and_success_verifies_measurements_and_work_orders(): void
    {
        $ticket = $this->ticket();
        CommerceSite::create(['site_key' => 'dressafford', 'name' => 'DressAfford', 'brand_name' => 'DressAfford', 'public_base_url' => 'https://www.dressafford.com', 'admin_api_url' => 'https://manager.test/api', 'api_token' => 'secret', 'enabled' => true]);
        $committed = false;
        $base = $this->order();
        Http::fake(function ($r) use (&$committed, $base) {
            if (str_contains($r->url(), 'options')) {
                return Http::response(['success' => true, 'data' => ['size_chart' => ['20' => ['bust' => '46', 'waist' => '40', 'hips' => '49']]]]);
            }
            if ($r->method() === 'PATCH') {
                if ($r['preview']) {
                    return Http::response(['success' => true, 'data' => ['allowed' => true, 'guard_contract' => 'ticket-order-state-v1', 'confirm_token' => 'size-secret', 'expires_at' => now()->addMinutes(5)->toIso8601String(), 'diff' => ['size' => ['old' => '18', 'new' => '20']]]]);
                }
                $committed = true;

                return Http::response(['success' => true, 'data' => ['changed' => true]]);
            }
            if (str_contains($r->url(), 'work-orders')) {
                return Http::response(['success' => true, 'data' => ['items' => [['id' => 1, 'order_no' => $base['order_no'], 'work_order_no' => 'dad-1', 'product_sn' => '717905', 'item_code' => 'dad-1', 'work_process' => 0, 'product_status' => 0, 'size' => $committed ? '20' : '18']], 'pagination' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]]);
            }
            $order = $base;
            if ($committed) {
                $order['items'][0]['size'] = '20';
                $order['items'][0]['custom_measurements'] = ['Bust' => '46', 'Waist' => '40', 'Hips' => '49'];
            }

            return Http::response(['success' => true, 'data' => $order]);
        });
        $policy = $this->policy('standard_size_change');
        app(TicketOrderWorkbench::class)->inspect($ticket);
        $service = app(TicketOrderActionService::class);
        $proposal = $service->preview($ticket, 'standard_size_change', 1, '20', $policy->id, 'Customer confirmed size 20', 1);
        $this->assertSame('46', $proposal->parameters['expected_measurements']['Bust']);
        config(['services.console_new.ticket_actions_enabled' => true]);
        $this->assertSame('succeeded', $service->execute($ticket, $proposal, 1)->status);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['preview'] === false && is_array($r['expected_state']) && $r['send_email'] === 0);
        $count = count(Http::recorded());
        $service->execute($ticket, $proposal, 1);
        $this->assertCount($count, Http::recorded());
    }

    public function test_source_rejection_and_order_lock_cannot_report_success(): void
    {
        $ticket = $this->ticket();
        $this->fake($this->order());
        $policy = $this->policy('production_cancellation');
        app(TicketOrderWorkbench::class)->inspect($ticket);
        $service = app(TicketOrderActionService::class);
        $proposal = $service->preview($ticket, 'cancel_order', null, null, $policy->id, 'Cancel as requested', 1);
        config(['services.console_new.ticket_actions_enabled' => true]);
        $lock = Cache::lock('ticket-order:'.hash('sha256', 'dad20261021-38183'), 60);
        $lock->get();
        try {
            $service->execute($ticket, $proposal, 1);
            $this->fail('Concurrent order work must block.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('正在处理', $e->getMessage());
        } finally {
            $lock->release();
        }
        Http::swap(new Factory);
        $order = $this->order();
        Http::fake(function ($r) use ($order) {
            if ($r->method() === 'PATCH') {
                return Http::response(['success' => false, 'errors' => [['code' => 'FORBIDDEN', 'message' => 'Forbidden']]], 403);
            }
            if (str_contains($r->url(), 'work-orders')) {
                return Http::response(['success' => true, 'data' => ['items' => [['id' => 1, 'order_no' => $order['order_no'], 'work_order_no' => 'dad-1', 'product_sn' => '717905', 'item_code' => 'dad-1', 'size' => '18', 'work_process' => 0, 'product_status' => 0]], 'pagination' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]]]);
            }

            return Http::response(['success' => true, 'data' => $order]);
        });
        $this->assertSame('failed', $service->execute($ticket, $proposal, 1)->status);
        $this->assertSame('FORBIDDEN', $proposal->fresh()->execution_result['error_code']);
    }

    public function test_action_permissions_are_enforced_even_when_viewer_has_route_access(): void
    {
        $ticket = $this->ticket();
        $viewer = Administrator::create(['username' => 'viewer', 'password' => bcrypt('test'), 'name' => 'Viewer']);
        $role = Role::create(['slug' => 'viewer', 'name' => 'Viewer']);
        $permission = Permission::create(['name' => 'View tickets', 'slug' => 'tickets-view', 'http_method' => '', 'http_path' => '/conversations*']);
        $role->permissions()->sync([$permission->id]);
        $viewer->roles()->sync([$role->id]);
        $this->actingAs($viewer, 'admin')->post('/admin/conversations/'.$ticket->id.'/preview-action', [])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_readonly_order_action_drafts_require_guidance_review_and_cannot_claim_refund_execution(): void
    {
        $ticket = $this->ticket();
        $ticket->update(['draft_guidance' => 'Factory has not started production.', 'draft_guidance_updated_at' => now()]);
        $this->fake($this->order());
        app(TicketOrderWorkbench::class)->inspect($ticket);
        $service = app(N8nTicketDraftService::class);
        $create = function () use ($ticket, $service) {
            $item = app(TicketWorkItemManager::class)->create($ticket, 1, $service::WORKFLOW_KEY, $service::WORKFLOW_VERSION);
            $service->start($item, 'review-test');
            $service->resolveFacts($item, 'review-test', ['clauses' => [['text' => 'Cancel my order', 'intent' => 'production_cancellation']], 'policy_keys' => ['ticket_order_action_review_process']]);
            $service->policies($item, 'review-test', ['ticket_order_action_review_process']);

            return $item;
        };
        $review = ['approved' => true, 'must_block' => false, 'covered_intents' => ['production_cancellation'], 'final_answer' => 'Your order is awaiting production. Cancellation eligibility requires a separate review.'];
        $item = $create();
        $result = $service->complete($item, 'review-test', $review);
        $this->assertSame('blocked', $result['draft_status']);
        $item = $create();
        $review['guidance_conflicts'] = [];
        $review['guidance_evidence'] = 'Factory has not started production.';
        config(['services.n8n.work_internal_api_token' => 'ticket-test-token']);
        $review['safety_flags'] = [];
        $this->withToken('ticket-test-token')->postJson('/api/internal/n8n/ticket-work-items/'.$item->work_item_key.'/complete', [
            'execution_id' => 'review-test', 'review' => $review,
        ])->assertOk()->assertJsonPath('draft_status', 'draft');
        foreach ([['Cancellation is not verified.'], [['guidance' => 'Factory has not started production.', 'conflict' => 'Cancellation is not verified.', 'policy_keys' => ['ticket_order_action_review_process']]]] as $conflicts) {
            $item = $create();
            $review['guidance_conflicts'] = $conflicts;
            $response = $this->withToken('ticket-test-token')->postJson('/api/internal/n8n/ticket-work-items/'.$item->work_item_key.'/complete', [
                'execution_id' => 'review-test', 'review' => $review,
            ])->assertOk()->assertJsonPath('draft_status', 'blocked');
            $draft = ConversationDraft::findOrFail($response->json('draft_id'));
            $this->assertSame($conflicts, data_get($draft->metadata, 'review.guidance_conflicts'));
            $this->assertSame('completed', $item->fresh()->status);
        }
        $item = $create();
        $review['guidance_conflicts'] = [];
        $review['final_answer'] = 'Your refund has been issued.';
        $result = $service->complete($item, 'review-test', $review);
        $this->assertSame('blocked', $result['draft_status']);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_configuration_flag_alone_cannot_enable_unguarded_source_api_writes(): void
    {
        $ticket = $this->ticket();
        $this->fake($this->order());
        $policy = $this->policy('production_cancellation');
        app(TicketOrderWorkbench::class)->inspect($ticket);
        $service = app(TicketOrderActionService::class);
        $proposal = $service->preview($ticket, 'cancel_order', null, null, $policy->id, 'Cancel as requested', 1);
        $evidence = $proposal->evidence;
        unset($evidence['preview']['guard_contract']);
        $proposal->update(['evidence' => $evidence]);
        config(['services.console_new.ticket_actions_enabled' => true]);
        try {
            $service->execute($ticket, $proposal, 1);
            $this->fail('Source must explicitly support atomic validation.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('原子状态校验', $e->getMessage());
        }
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH' && $r['preview'] === false);
    }

    private function ticket(): Conversation
    {
        $a = ChannelAccount::create(['channel' => 'helpdesk', 'external_account_id' => 'test-account']);
        $t = Conversation::create(['channel_account_id' => $a->id, 'site_key' => 'dressafford.com', 'external_id' => 'test-ticket', 'external_short_id' => 'D7OGP', 'subject' => 'Order question', 'requester_email' => 'customer@example.test', 'status' => 'open', 'silo' => 'tickets', 'raw_payload' => []]);
        ConversationEvent::create(['conversation_id' => $t->id, 'external_id' => 'event-1', 'event_type' => 'message', 'author_type' => 'client', 'body_text' => 'Please cancel dad20261021-38183', 'occurred_at' => now(), 'content_hash' => hash('sha256', 'customer-message'), 'raw_payload' => []]);

        return $t;
    }

    private function order(): array
    {
        return ['order_no' => 'dad20261021-38183', 'customer' => ['email' => 'customer@example.test'], 'site' => ['url' => 'https://www.dressafford.com'], 'status' => ['order_status' => 1, 'order_status_text' => '处理中', 'shipping_status' => 0, 'shipping_status_text' => '未发货'], 'amounts' => ['pay_amount' => '213.96'], 'items' => [['id' => 1, 'product_sn' => '717905', 'item_code' => 'dad-1', 'product_name' => 'Dress', 'type' => 'dress', 'status' => 0, 'size' => '18', 'custom_measurements' => ['Bust' => '44']]], 'refunds' => [], 'packages' => [], 'shipping' => []];
    }

    private function fake(array $order, int $pages = 1, bool $cancelledWork = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) use ($order, $pages, $cancelledWork) {
            if ($r->method() === 'PATCH') {
                return Http::response(['success' => true, 'data' => ['allowed' => true, 'guard_contract' => 'ticket-order-state-v1', 'confirm_token' => 'preview-secret', 'expires_at' => now()->addMinutes(5)->toIso8601String(), 'diff' => [['old' => 1, 'new' => -1]]]]);
            }
            if (str_contains($r->url(), 'work-orders')) {
                parse_str(parse_url($r->url(), PHP_URL_QUERY), $query);
                $page = (int) $query['page'];

                return Http::response(['success' => true, 'data' => ['items' => [['id' => $page, 'order_no' => $order['order_no'], 'work_order_no' => 'dad-'.$page, 'product_sn' => '717905', 'item_code' => 'dad-1', 'size' => '18', 'work_process' => 0, 'product_status' => $cancelledWork ? -1 : 0]], 'pagination' => ['current_page' => $page, 'last_page' => $pages, 'total' => $pages]]]);
            }

            return Http::response(['success' => true, 'data' => $order]);
        });
    }

    private function policy(string $intent): KnowledgeArticle
    {
        $p = KnowledgeArticle::create(['key' => 'test_'.$intent, 'title' => 'Test policy', 'knowledge_type' => 'policy', 'status' => 'verified', 'version' => 1, 'policy_rules' => ['Verify eligibility manually.']]);
        app(KnowledgeResolver::class)->bind($p, 'ticket', $intent);

        return $p;
    }

    private function admin(): Administrator
    {
        $a = Administrator::create(['username' => 'tester', 'password' => bcrypt('test'), 'name' => 'Tester']);
        $role = Role::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $a->roles()->sync([$role->id]);

        return $a;
    }
}
