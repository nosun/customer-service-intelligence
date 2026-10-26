<?php

namespace Tests\Feature;

use App\Jobs\RunTicketAgent;
use App\Models\BusinessContextSnapshot;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationDraft;
use App\Models\KnowledgeArticle;
use App\Models\WorkItem;
use App\Services\Agents\TicketAgentDocuments;
use App\Services\Agents\TicketAgentOutput;
use App\Services\Agents\TicketAgentTools;
use App\Services\Agents\TicketAgentWorker;
use App\Services\Agents\TicketAgentWorkflow;
use App\Services\Agents\TicketReturnAssessment;
use App\Services\TicketWork\TicketAssistanceView;
use App\Services\TicketWork\TicketFactResolver;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class TicketAgentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private array $auditPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        File::delete($this->auditPaths);
        parent::tearDown();
    }

    public function test_complete_investigation_review_and_bilingual_save_reuses_the_human_review_ui(): void
    {
        [$ticket, $actor] = $this->ticket();
        $this->fakeWorker();
        $workflow = app(TicketAgentWorkflow::class);
        $item = $workflow->run($this->task($ticket, $actor));
        $this->assertSame('completed', $item->status);
        $this->assertSame('draft', $item->result['draft_status']);
        $draft = ConversationDraft::findOrFail($item->result['draft_id']);
        $this->assertSame('openai_agents', $draft->source);
        $this->assertSame('感谢确认，我们已记录退运资料。', $draft->metadata['answer_zh']);
        $this->assertTrue($draft->requires_human_review);
        $this->assertNull($draft->sent_at);
        $this->assertTrue($workflow->current($ticket, $item));
        $this->assertTrue(app(TicketAssistanceView::class)->current($ticket, $item));
        $this->assertSame(0600, fileperms($this->auditPaths[0]) & 0777);
        $this->actingAs($actor, 'admin')->get('/admin/conversations/'.$ticket->id)->assertOk()
            ->assertSee('Agents SDK 调查并拟稿')->assertSee('中文对应邮件')->assertSee('AI Draft 人工审核')
            ->assertSee('已确认退回，待核查收件。');
        $this->post('/admin/conversations/'.$ticket->id.'/drafts/'.$draft->id.'/review', ['review_outcome' => 'accepted_as_is'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('accepted_as_is', $draft->fresh()->review_outcome);
        $this->assertDatabaseCount('action_proposals', 0);
        Http::assertNothingSent();
    }

    public function test_final_review_failure_saves_blocked_and_cannot_be_adopted(): void
    {
        [$ticket, $actor] = $this->ticket();
        $this->fakeWorker('review_failed');
        $item = app(TicketAgentWorkflow::class)->run($this->task($ticket, $actor));
        $draft = ConversationDraft::findOrFail($item->result['draft_id']);
        $this->assertSame('blocked', $draft->status);
        $this->actingAs($actor, 'admin')->post('/admin/conversations/'.$ticket->id.'/drafts/'.$draft->id.'/review', ['review_outcome' => 'accepted_as_is'])
            ->assertSessionHasErrors('review_outcome');
        $this->assertNull($draft->fresh()->reviewed_at);
    }

    public function test_stage_two_is_visible_and_waiting_or_missing_template_cannot_be_saved_as_ready(): void
    {
        foreach (['stage_two' => 'draft', 'holding' => 'blocked', 'stage_regression' => 'blocked', 'missing_template' => 'blocked'] as $mode => $status) {
            [$ticket, $actor] = $this->ticket();
            $this->fakeWorker($mode);
            $item = app(TicketAgentWorkflow::class)->run($this->task($ticket, $actor));
            $this->assertSame('offer_options', $item->result['return_assessment']['stage']);
            $this->assertSame($status, $item->result['draft_status']);
            $this->actingAs($actor, 'admin')->get('/admin/conversations/'.$ticket->id)->assertOk()
                ->assertSee('第二阶段：提供退货方案')->assertSee('RET-02')
                ->assertSee('查看流程版本与当前步骤的完成标准')->assertSee('eligible_proposal');
            if ($status === 'blocked') {
                $this->post('/admin/conversations/'.$ticket->id.'/drafts/'.$item->result['draft_id'].'/review', ['review_outcome' => 'accepted_as_is'])
                    ->assertSessionHasErrors('review_outcome');
            }
        }
    }

    public function test_no_reply_after_a_prior_agent_response_saves_assessment_without_a_new_email(): void
    {
        [$ticket, $actor] = $this->ticket(true);
        $this->fakeWorker('no_reply');
        $item = app(TicketAgentWorkflow::class)->run($this->task($ticket, $actor));
        $this->assertSame('completed', $item->status);
        $this->assertSame('no_reply', $item->result['draft_status']);
        $this->assertDatabaseCount('conversation_drafts', 0);
    }

    public function test_conflict_after_agreement_keeps_the_stage_but_blocks_ready_even_if_the_model_approves(): void
    {
        [$ticket, $actor] = $this->ticket();
        $this->fakeWorker('accepted_conflict');
        $item = app(TicketAgentWorkflow::class)->run($this->task($ticket, $actor));
        $this->assertSame('send_instructions', $item->result['return_assessment']['stage']);
        $this->assertTrue($item->result['return_assessment']['requires_internal_review']);
        $this->assertSame('blocked', $item->result['draft_status']);
        $draft = ConversationDraft::findOrFail($item->result['draft_id']);
        $this->assertSame('accepted_offer', $draft->metadata['return_assessment']['routing_rule']);
        $this->assertSame('1.1.0', $draft->metadata['return_assessment']['strategy_version']);
        $this->assertNull($draft->sent_at);
    }

    public function test_invalid_assessment_clears_prior_selection_and_cannot_reuse_a_template(): void
    {
        [$ticket] = $this->ticket();
        $tools = new TicketAgentTools($ticket);
        $context = $tools->call('read_conversation', []);
        $tools->call('record_plan', $this->plan());
        $tools->call('read_policy', ['key' => 'agent_return']);
        $tools->call('read_return_playbook', []);
        $event = 'event:'.$context['messages'][1]['event_id'];
        $valid = ['applicable' => true, 'reason' => 'Customer reports return dispatch.', 'evidence_refs' => [$event],
            'conditions' => array_map(fn ($key) => ['key' => $key, 'status' => 'missing', 'explanation' => 'Earlier details unavailable.', 'evidence_refs' => []], TicketReturnAssessment::CONDITIONS),
            'customer_position' => 'return_shipped', 'position_evidence_refs' => [$event], 'exception_reason' => ''];
        $this->assertSame('track_return', $tools->call('record_return_assessment', $valid)['assessment']['stage']);
        $this->assertSame('invalid_assessment', $tools->call('record_return_assessment', [...$valid, 'conditions' => []])['status']);
        $this->assertNull($tools->returnAssessment);
        $this->expectException(RuntimeException::class);
        $tools->call('read_return_template', []);
    }

    public function test_unread_evidence_changed_email_or_input_during_run_fails_without_saving_a_draft(): void
    {
        foreach (['invented_evidence', 'changed_email', 'changed_decision', 'changed_input', 'provider_failed'] as $mode) {
            [$ticket, $actor] = $this->ticket();
            $this->fakeWorker($mode);
            $item = $this->task($ticket, $actor);
            try {
                app(TicketAgentWorkflow::class)->run($item);
                $this->fail('Invalid result must not save.');
            } catch (RuntimeException) {
                $this->assertSame('failed', $item->fresh()->status);
                $this->assertFalse(ConversationDraft::where('work_item_id', $item->id)->exists());
            }
        }
    }

    public function test_new_attachment_content_guidance_policy_and_identity_invalidate_current_results(): void
    {
        foreach (['attachment', 'guidance', 'policy', 'identity', 'body'] as $change) {
            [$ticket] = $this->ticket();
            $tools = new TicketAgentTools($ticket);
            match ($change) {
                'attachment' => $ticket->events()->first()->attachments()->create(['external_key' => uniqid(), 'filename' => 'new.pdf', 'raw_payload' => []]),
                'guidance' => $ticket->update(['draft_guidance' => 'New facts']),
                'policy' => KnowledgeArticle::where('key', 'agent_return')->increment('version'),
                'identity' => $ticket->update(['requester_email' => 'changed@example.test']),
                'body' => $ticket->events()->first()->update(['body_text' => 'Changed without content hash update']),
            };
            try {
                $tools->assertCurrent();
                $this->fail('Input change must invalidate the run.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('changed', $e->getMessage());
            }
        }
    }

    public function test_document_catalog_includes_separate_attachment_events_and_cannot_read_other_tickets_or_private_files(): void
    {
        [$ticket] = $this->ticket();
        [$other] = $this->ticket();
        $event = $ticket->events()->create(['external_id' => uniqid(), 'event_type' => 'attachments', 'author_type' => 'client', 'is_private' => false, 'occurred_at' => now(), 'content_hash' => hash('sha256', 'attachment event'), 'raw_payload' => []]);
        $attachment = $event->attachments()->create(['external_key' => uniqid(), 'filename' => 'postal.pdf', 'remote_url' => 'https://cdn.livechat-files.com/dal/api/file/helpdesk/att/test.pdf', 'raw_payload' => []]);
        $private = $other->events()->first()->attachments()->create(['external_key' => uniqid(), 'filename' => 'other.pdf', 'raw_payload' => []]);
        $tools = new TicketAgentTools($ticket);
        $context = $tools->call('read_conversation', []);
        $this->assertContains('attachment:'.$attachment->id, array_column($context['documents'], 'id'));
        $this->assertContains($event->id, array_column($context['messages'], 'event_id'));
        $this->assertNotContains('attachment:'.$private->id, array_column($context['documents'], 'id'));
        $tools->call('record_plan', $this->plan());
        $this->mock(TicketAgentDocuments::class)->shouldReceive('inspect')->once()->andReturn(['status' => 'read', 'pages' => [['reference_numbers' => ['LH123456789US']]]]);
        $document = $tools->call('inspect_document', ['document_id' => 'attachment:'.$attachment->id]);
        $this->assertSame('read', $document['status']);
        $this->expectExceptionMessage('not in the current Ticket');
        $tools->call('inspect_document', ['document_id' => 'attachment:'.$private->id]);
    }

    public function test_unverified_other_site_and_wrong_intent_policies_cannot_be_read(): void
    {
        [$ticket] = $this->ticket();
        foreach (['observed', 'other_site', 'wrong_intent'] as $variant) {
            $article = KnowledgeArticle::create(['key' => uniqid('excluded'), 'title' => 'Excluded', 'status' => $variant === 'observed' ? 'observed' : 'verified',
                'knowledge_type' => 'policy', 'site_key' => $variant === 'other_site' ? 'other.test' : null, 'version' => 1]);
            $article->bindings()->create(['consumer' => 'ticket', 'intent_key' => $variant === 'wrong_intent' ? 'presales_consultation' : 'refund_review', 'usage' => 'draft', 'enabled' => true]);
            $tools = new TicketAgentTools($ticket);
            $tools->call('read_conversation', []);
            $tools->call('record_plan', $this->plan());
            $result = $tools->call('read_policy', ['key' => $article->key]);
            $this->assertSame('unavailable', $result['status']);
            $this->assertSame([], $result['evidence_refs']);
            $this->assertSame([], $tools->policies);
        }
    }

    public function test_stale_queue_and_duplicate_dispatch_do_not_create_or_run_two_drafts(): void
    {
        [$ticket, $actor] = $this->ticket();
        $workflow = app(TicketAgentWorkflow::class);
        $item = $this->task($ticket, $actor);
        $this->assertSame($item->id, $workflow->create($ticket, $actor->id, 'omniroute')->id);
        $ticket->update(['draft_guidance' => 'Changed after dispatch']);
        $this->mock(TicketAgentWorker::class)->shouldNotReceive('run');
        $this->assertSame('stale', $workflow->run($item)->status);
        $this->assertDatabaseCount('conversation_drafts', 0);
    }

    public function test_ui_dispatches_a_background_sdk_job_without_n8n_or_business_requests(): void
    {
        [$ticket, $actor] = $this->ticket();
        $this->actingAs($actor, 'admin')->post('/admin/conversations/'.$ticket->id.'/agent-assist')->assertRedirect()->assertSessionHasNoErrors();
        Queue::assertPushed(RunTicketAgent::class, fn ($job) => $job->connection === 'background');
        $this->assertSame(TicketAgentWorkflow::KEY, WorkItem::first()->workflow_key);
        Http::assertNothingSent();
    }

    public function test_return_order_facts_keep_item_prices_and_actual_capture_time_without_inventing_fee_or_currency(): void
    {
        [$ticket] = $this->ticket();
        $captured = now()->subSeconds(5);
        BusinessContextSnapshot::create(['conversation_id' => $ticket->id, 'source' => 'console_new', 'context_type' => 'ticket_order',
            'lookup_status' => 'found', 'queried_at' => $captured, 'summary' => ['order' => [
                'amounts' => ['total_amount' => 100, 'pay_amount' => 120],
                'items' => [['type' => 'dress', 'size' => '6', 'quantity' => 1, 'unit_price' => 100, 'total_amount' => 100, 'private_note' => 'PRIVATE']],
            ]]]);
        $this->mock(TicketFactResolver::class)->shouldReceive('resolveInvestigation')->once()->andReturn([
            'facts' => [['key' => 'order.identity_match', 'value' => true, 'source' => 'test', 'captured_at' => now()->toIso8601String(),
                'fresh_until' => now()->addMinutes(10)->toIso8601String(), 'sensitivity' => 'internal', 'shareable_with_ai' => true]], 'missing_facts' => [], 'safety_flags' => [],
        ]);
        $tools = new TicketAgentTools($ticket);
        $tools->call('read_conversation', []);
        $tools->call('record_plan', $this->plan());
        $result = $tools->call('read_order', []);
        $facts = collect($result['facts'])->keyBy('key');
        $this->assertSame(100, $facts['order.return_items']['value'][0]['total_amount']);
        $this->assertSame(['total_amount' => 100, 'pay_amount' => 120], $facts['order.return_amounts']['value']);
        $this->assertSame($captured->toIso8601String(), $facts['order.identity_match']['captured_at']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
        $this->assertFalse($facts->has('order.currency'));
    }

    private function task(Conversation $ticket, Administrator $actor): WorkItem
    {
        $item = app(TicketAgentWorkflow::class)->create($ticket, $actor->id, 'omniroute');
        $this->auditPaths[] = storage_path('app/private/ticket-agent-runs/'.$item->work_item_key.'.json');

        return $item;
    }

    private function plan(bool $noReply = false): array
    {
        return ['clauses' => [['text' => 'Return progress', 'intent' => $noReply ? 'conversation_update' : 'refund_review', 'business_stage' => 'after_sales']],
            'policy_keys' => ['agent_return'], 'unsupported_requests' => [], 'customer_language' => 'en', 'steps' => ['Read applicable policy', 'Assess the latest message']];
    }

    private function fakeWorker(string $mode = 'reply'): void
    {
        $this->mock(TicketFactResolver::class)->shouldReceive('resolveInvestigation')->andReturnUsing(fn () => [
            'facts' => [['key' => 'order.identity_match', 'value' => true, 'source' => 'test', 'captured_at' => now()->toIso8601String(), 'fresh_until' => now()->addMinutes(10)->toIso8601String(), 'sensitivity' => 'internal', 'shareable_with_ai' => true]], 'missing_facts' => [], 'safety_flags' => [],
        ]);
        $this->mock(TicketAgentWorker::class)->shouldReceive('run')->andReturnUsing(function ($initial, $provider, $invoke) use ($mode) {
            if ($mode === 'provider_failed') {
                throw new RuntimeException('Model failed');
            }
            $context = $invoke('read_conversation', []);
            $plan = $invoke('record_plan', $this->plan($mode === 'no_reply'))['plan'];
            $invoke('read_policy', ['key' => 'agent_return']);
            if ($mode !== 'no_reply') {
                $invoke('read_order', []);
            }
            $ids = array_column($context['messages'], 'event_id');
            $stageTwo = in_array($mode, ['stage_two', 'holding', 'stage_regression', 'missing_template'], true);
            $invoke('read_return_playbook', []);
            $assessment = $invoke('record_return_assessment', [
                'applicable' => true, 'reason' => 'Customer reports dispatch under the prior agreed refund.', 'evidence_refs' => ['event:'.$ids[0]],
                'conditions' => array_map(fn ($key) => ['key' => $key, 'status' => $stageTwo ? 'satisfied' : 'missing',
                    'explanation' => 'Evidence-backed fixture condition.', 'evidence_refs' => $stageTwo ? ['event:'.$ids[1], 'policy:agent_return@1'] : []], TicketReturnAssessment::CONDITIONS),
                'customer_position' => $stageTwo ? 'conditions_confirmed' : ($mode === 'accepted_conflict' ? 'option_accepted' : 'return_shipped'),
                'position_evidence_refs' => ['event:'.$ids[1]], 'exception_reason' => $mode === 'accepted_conflict' ? 'Different item identity discovered after agreement.' : '',
            ])['assessment'];
            if ($mode !== 'missing_template') {
                $invoke('read_return_template', []);
            }
            $candidate = [
                'return_reply' => $mode === 'no_reply' ? null : ['stage' => $assessment['stage'], 'template_id' => $assessment['template_id'],
                    'readiness' => $mode === 'holding' ? 'holding' : 'ready', 'requirements_covered' => $assessment['reply_requirements'], 'gaps' => []],
                'case_progress' => ['stage' => $stageTwo ? ($mode === 'stage_regression' ? 'awaiting_internal' : 'awaiting_acceptance') : 'awaiting_return', 'summary' => '已确认退回，待核查收件。', 'waiting_for' => 'internal', 'next_action' => '核查收件', 'follow_up_note' => '', 'evidence_event_ids' => $ids],
                'response_decision' => ['mode' => $mode === 'no_reply' ? 'no_reply' : 'reply', 'reason' => '当前阶段判断', 'evidence_event_ids' => $ids],
                'answer_units' => [['request_id' => 'request_1', 'business_stage' => 'after_sales', 'business' => 'refund_review', 'action_stage' => $mode === 'no_reply' ? 'information' : 'requested', 'points' => ['确认资料'],
                    'evidence_refs' => ['event:'.$ids[0], 'policy:agent_return@1'], 'essential_unknowns' => [], 'internal_followups' => []]],
                'internal_followups' => ['Check receiving records'], 'missing_facts' => ['return.receipt'],
                'conclusions' => [['statement' => '等待内部收件核实', 'evidence_refs' => ['event:'.$ids[0]]]],
                'answer_en' => $mode === 'no_reply' ? '' : ($stageTwo ? 'Thank you for confirming. You can return the dress for its product price after receipt and inspection. Original shipping is excluded and return postage is yours. Would you like to proceed?' : 'Thank you for confirming. We have noted your return documents.'),
                'answer_customer_language' => $mode === 'no_reply' ? '' : ($stageTwo ? 'Thank you for confirming. You can return the dress for its product price after receipt and inspection. Original shipping is excluded and return postage is yours. Would you like to proceed?' : 'Thank you for confirming. We have noted your return documents.'),
                'answer_zh' => $mode === 'no_reply' ? '' : ($stageTwo ? '感谢确认。您可寄回礼服，签收并检查后退还商品价。原运费不退，寄回邮费由您承担。您是否愿意按此方案退回？' : '感谢确认，我们已记录退运资料。'),
            ];
            $hash = app(TicketAgentOutput::class)->candidateHash($candidate);
            $review = ['approved' => $mode !== 'review_failed', 'candidate_hash' => $hash, 'problems' => $mode === 'review_failed' ? ['Needs repair'] : [],
                'covered_request_ids' => ['request_1'], 'quality_checks' => array_fill_keys(TicketAgentOutput::CHECKS, true)];
            if ($mode === 'invented_evidence') {
                $candidate['conclusions'][0]['evidence_refs'] = ['document:unread'];
            }
            if ($mode === 'changed_email') {
                $candidate['answer_zh'] = 'Changed after review';
            }
            if ($mode === 'changed_decision') {
                $candidate['case_progress']['next_action'] = 'Changed after independent review';
            }
            if ($mode === 'changed_input') {
                Conversation::findOrFail((int) substr($initial['case_key'], 7))->update(['draft_guidance' => 'New guidance during run']);
            }

            return ['status' => $mode === 'review_failed' ? 'blocked' : 'needs_review', 'plan' => $plan, 'candidate' => $candidate, 'reviews' => [$review]];
        });
    }

    private function ticket(bool $latestAgent = false): array
    {
        $actor = Administrator::firstOrCreate(['username' => 'sdk-test'], ['password' => bcrypt('test'), 'name' => 'SDK test']);
        $role = Role::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $actor->roles()->syncWithoutDetaching([$role->id]);
        $account = ChannelAccount::firstOrCreate(['channel' => 'helpdesk', 'external_account_id' => 'sdk-test']);
        $ticket = Conversation::create(['channel_account_id' => $account->id, 'external_id' => uniqid('sdk-ticket'), 'source_type' => 'helpdesk',
            'silo' => 'tickets', 'status' => 'open', 'site_key' => 'example.test', 'requester_email' => 'customer@example.test', 'subject' => 'Return', 'raw_payload' => []]);
        foreach ([['agent', 'Agreed refund after receipt and inspection.'], [$latestAgent ? 'agent' : 'client', $latestAgent ? 'We have noted your documents.' : 'I agree and have shipped it.']] as $index => [$author, $body]) {
            $ticket->events()->create(['external_id' => uniqid('sdk-event'), 'event_type' => 'message', 'author_type' => $author, 'is_private' => false,
                'body_text' => $body, 'content_hash' => hash('sha256', $body), 'occurred_at' => now()->subMinutes(2 - $index), 'raw_payload' => []]);
        }
        $article = KnowledgeArticle::firstOrCreate(['key' => 'agent_return'], ['title' => 'Return process', 'status' => 'verified', 'knowledge_type' => 'process', 'version' => 1,
            'policy_rules' => ['Check documents, receipt and inspection before a refund.'], 'automation_tier' => 'draft_sensitive']);
        foreach (['refund_review', 'conversation_update'] as $intent) {
            $article->bindings()->firstOrCreate(['consumer' => 'ticket', 'intent_key' => $intent, 'usage' => 'draft'], ['enabled' => true]);
        }

        return [$ticket, $actor];
    }
}
