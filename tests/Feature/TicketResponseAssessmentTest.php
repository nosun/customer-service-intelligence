<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationDraft;
use App\Services\TicketWork\N8nTicketDraftService;
use App\Services\TicketWork\TicketDraftInput;
use App\Services\TicketWork\TicketReplyPlan;
use App\Services\TicketWork\TicketResponseAssessment;
use App\Services\TicketWork\TicketRuntimeContext;
use App\Services\TicketWork\TicketWorkItemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TicketResponseAssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_related_history_requires_exact_site_account_customer_and_order_and_preserves_time_boundary(): void
    {
        $ticket = $this->ticket();
        $this->message($ticket, 'client', 'I will send the video Saturday.', now()->subMinute());
        $related = $this->ticket(['status' => 'closed', 'draft_guidance' => 'Historical carrier investigation.', 'draft_guidance_updated_at' => now()->subHour()]);
        $old = $this->message($related, 'agent', 'Please send the packaging video.', now()->subHours(2));
        $this->message($related, 'agent', 'Future message.', now()->addHour());
        $otherAccount = ChannelAccount::create(['channel' => 'helpdesk', 'external_account_id' => 'other']);
        foreach ([['site_key' => 'another.test'], ['linked_order_number' => 'another-order'], ['requester_email' => 'another@example.test'], ['channel_account_id' => $otherAccount->id]] as $attributes) {
            $wrong = $this->ticket($attributes);
            $this->message($wrong, 'agent', 'Unrelated private history.', now()->subHour());
        }
        $context = app(TicketRuntimeContext::class);
        $built = $context->build($ticket, $context->triggerEvent($ticket), true);
        $this->assertSame([$old->id], array_column($built['related_messages'], 'event_id'));
        $this->assertSame('Historical carrier investigation.', $built['related_conversations'][0]['historical_guidance']);
        $this->assertNull($ticket->draftGuidance());
    }

    public function test_related_changes_after_trigger_invalidate_fingerprint_without_leaking_future_message_into_history(): void
    {
        $ticket = $this->ticket();
        $this->message($ticket, 'client', 'Thanks.', now()->subMinute());
        $related = $this->ticket();
        $this->message($related, 'agent', 'Old answer.', now()->subHour());
        $input = app(TicketDraftInput::class);
        $capture = fn () => $input->capture($ticket, 1, N8nTicketDraftService::INVESTIGATION_KEY, N8nTicketDraftService::INVESTIGATION_VERSION);
        $before = $capture();
        $this->message($related, 'agent', 'New public update.', now());
        $after = $capture();
        $this->assertNotSame($before['hash'], $after['hash']);
        $this->assertCount(1, $after['snapshot']['related_history']['related_messages']);
        $related->update(['draft_guidance' => 'New investigation.', 'draft_guidance_updated_at' => now()]);
        $this->assertNotSame($after['hash'], $capture()['hash']);
    }

    public function test_oversized_history_is_explicitly_incomplete_and_never_silently_treated_as_complete(): void
    {
        $ticket = $this->ticket();
        $event = $this->message($ticket, 'client', 'Thanks.', now());
        for ($i = 0; $i < 21; $i++) {
            $this->ticket();
        }
        $history = app(TicketRuntimeContext::class)->relatedHistory($ticket, $event);
        $this->assertTrue($history['history_incomplete']);
        $this->assertCount(20, $history['related_conversations']);
    }

    public function test_new_contribution_is_separate_from_quoted_old_request(): void
    {
        $ticket = $this->ticket();
        $event = $this->message($ticket, 'client', "I will send the video Saturday.\n-------- Message d'origine --------\nPlease refund me.", now());
        $built = app(TicketRuntimeContext::class)->build($ticket, $event, true);
        $this->assertSame('I will send the video Saturday.', $built['messages'][0]['current_text']);
        $this->assertStringContainsString('Please refund me.', $built['messages'][0]['quoted_text']);
        $this->assertStringContainsString('Please refund me.', $built['messages'][0]['text']);
    }

    public function test_missing_order_does_not_guess_association_and_replay_does_not_read_real_history(): void
    {
        $ticket = $this->ticket(['linked_order_number' => null]);
        $event = $this->message($ticket, 'client', 'Thanks.', now());
        $other = $this->ticket();
        $this->message($other, 'agent', 'Private history.', now()->subHour());
        $context = app(TicketRuntimeContext::class);
        $this->assertSame([], $context->relatedHistory($ticket, $event)['related_messages']);
        $ticket->update(['linked_order_number' => $other->linked_order_number, 'source_type' => 'replay']);
        $this->assertSame([], $context->relatedHistory($ticket, $event)['related_messages']);
    }

    public function test_11104_like_update_can_acknowledge_without_order_query_and_keeps_progress(): void
    {
        [$service, $item, $plan] = $this->prepare('acknowledge');
        $normalized = $service->planReply($item, 'response-test', $plan)['reply_plan'];
        $this->assertSame('awaiting_customer', $normalized['case_progress']['stage']);
        $this->assertSame([], $normalized['blocking_reasons']);
        $review = $this->review('acknowledge', 'Thank you for keeping the packaging. You can send the video Saturday; there is no need to recreate the opening.');
        $result = $service->complete($item, 'response-test', $review);
        $this->assertSame('draft', $result['draft_status']);
        Http::assertNothingSent();
    }

    public function test_no_reply_completes_without_creating_an_empty_draft_or_closing_ticket(): void
    {
        [$service, $item, $plan, $ticket] = $this->prepare('no_reply');
        $service->planReply($item, 'response-test', $plan);
        config(['services.n8n.work_internal_api_token' => 'response-token']);
        $response = $this->withToken('response-token')->postJson("/api/internal/n8n/ticket-work-items/{$item->work_item_key}/complete",
            ['execution_id' => 'response-test', 'review' => $this->review('no_reply', '')])->assertOk();
        $result = $response->json();
        $this->assertSame('no_reply', $result['draft_status']);
        $this->assertNull($result['draft_id']);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertSame(0, ConversationDraft::count());
        $this->assertTrue($result['requires_human_review']);
        Http::assertNothingSent();
    }

    public function test_no_reply_disagreement_or_missing_quality_check_needs_review_instead_of_silent_success(): void
    {
        foreach (['disagreement', 'quality', 'text'] as $scenario) {
            [$service, $item, $plan] = $this->prepare('no_reply');
            $service->planReply($item, 'response-test', $plan);
            $review = $this->review('no_reply', '');
            if ($scenario === 'disagreement') {
                $review['response_decision_approved'] = false;
            } elseif ($scenario === 'quality') {
                unset($review['quality_checks']['intent_coverage']);
            } else {
                $review['final_answer'] = 'An unintended email.';
            }
            $result = $service->complete($item, 'response-test', $review);
            $this->assertSame('needs_review', $result['draft_status']);
        }
        $this->assertSame(0, ConversationDraft::count());
    }

    public function test_no_reply_rejects_fake_or_missing_events_and_substantive_requests(): void
    {
        foreach (['fake_event', 'no_agent', 'missing_trigger', 'substantive', 'incomplete'] as $scenario) {
            [$service, $item, $plan, $ticket] = $this->prepare('no_reply');
            $runtime = $item->fresh()->result['runtime'];
            $context = $runtime['ticket_context'];
            if ($scenario === 'fake_event') {
                $plan['response_decision']['evidence_event_ids'][] = 999999;
            } elseif ($scenario === 'no_agent') {
                $plan['response_decision']['evidence_event_ids'] = [$context['trigger_event_id']];
            } elseif ($scenario === 'missing_trigger') {
                $plan['response_decision']['evidence_event_ids'] = [$context['messages'][0]['event_id']];
            } elseif ($scenario === 'substantive') {
                $runtime['plan']['intents'] = ['refund_review'];
            } else {
                $context['history_incomplete'] = true;
            }
            try {
                app(TicketResponseAssessment::class)->validate($plan, $runtime, $context);
                $this->fail('Invalid no-reply recommendation passed: '.$scenario);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('response_decision', $exception->errors());
            }
        }
    }

    public function test_new_associated_message_during_generation_makes_decision_stale(): void
    {
        [$service, $item, $plan, $ticket] = $this->prepare('no_reply');
        $service->planReply($item, 'response-test', $plan);
        $related = $this->ticket();
        $this->message($related, 'client', 'I now need an answer.', now());
        $this->expectExceptionMessage('changed during execution');
        $service->complete($item, 'response-test', $this->review('no_reply', ''));
    }

    private function prepare(string $mode): array
    {
        $ticket = $this->ticket();
        $agent = $this->message($ticket, 'agent', 'Please send a video of the retained packaging when you return.', now()->subHours(2));
        $client = $this->message($ticket, 'client', 'I have kept the packaging and will send the video Saturday.', now()->subHour());
        $item = app(TicketWorkItemManager::class)->create($ticket, 1, N8nTicketDraftService::INVESTIGATION_KEY, N8nTicketDraftService::INVESTIGATION_VERSION);
        $service = app(N8nTicketDraftService::class);
        $service->start($item, 'response-test');
        $service->resolveFacts($item, 'response-test', ['clauses' => [['text' => $client->body_text, 'intent' => 'conversation_update']], 'policy_keys' => []]);
        $service->policies($item, 'response-test', []);
        $plan = ['answer_units' => [['request_id' => 'request_1', 'business_stage' => 'after_sales',
            'business' => 'conversation_update', 'action_stage' => 'information', 'points' => ['Customer will send video Saturday.'],
            'evidence_refs' => ['event:'.$client->id], 'guidance_quote' => '', 'essential_unknowns' => [], 'internal_followups' => []]],
            'response_decision' => ['mode' => $mode, 'reason' => 'The customer has acknowledged the evidence request.', 'evidence_event_ids' => [$agent->id, $client->id]],
            'case_progress' => ['stage' => 'awaiting_customer', 'summary' => 'Waiting for a future video, not a received attachment.',
                'waiting_for' => 'customer', 'next_action' => 'Review the video when supplied.', 'follow_up_note' => 'Check after the promised Saturday.', 'evidence_event_ids' => [$client->id]]];

        return [$service, $item, $plan, $ticket];
    }

    private function review(string $mode, string $answer): array
    {
        return ['approved' => true, 'must_block' => false, 'final_answer' => $answer, 'reviewed_answer' => $answer,
            'response_decision_approved' => true, 'reviewed_response_mode' => $mode,
            'covered_intents' => ['conversation_update'], 'covered_request_ids' => ['request_1'],
            'quality_checks' => array_fill_keys(TicketReplyPlan::QUALITY_CHECKS, true),
            'guidance_conflicts' => [], 'business_conflicts' => [], 'safety_flags' => []];
    }

    private function ticket(array $attributes = []): Conversation
    {
        $account = ChannelAccount::firstOrCreate(['channel' => 'helpdesk', 'external_account_id' => 'response-test']);

        return Conversation::create([...['channel_account_id' => $account->id, 'external_id' => uniqid(), 'raw_payload' => [],
            'subject' => 'Damaged dress', 'requester_email' => 'response@example.test', 'site_key' => 'dressafford.com',
            'linked_order_number' => 'dad20261001-10000', 'silo' => 'tickets', 'status' => 'open'], ...$attributes]);
    }

    private function message(Conversation $ticket, string $role, string $body, $time)
    {
        return $ticket->events()->create(['external_id' => uniqid(), 'event_type' => 'message', 'author_type' => $role,
            'raw_payload' => [], 'is_private' => false, 'body_text' => $body, 'content_hash' => hash('sha256', uniqid($body)), 'occurred_at' => $time]);
    }
}
