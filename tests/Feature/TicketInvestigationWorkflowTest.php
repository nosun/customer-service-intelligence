<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\CommerceProduct;
use App\Models\CommerceSite;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\KnowledgeArticle;
use App\Services\ProductQuestions\KnowledgeArticleVersionPublisher;
use App\Services\TicketWork\N8nTicketDraftService;
use App\Services\TicketWork\TicketFactResolver;
use App\Services\TicketWork\TicketReplyPlan;
use App\Services\TicketWork\TicketRuntimeContext;
use App\Services\TicketWork\TicketWorkItemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TicketInvestigationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.n8n.work_internal_api_token' => 'investigation-test']);
        Http::preventStrayRequests();
    }

    public function test_12067_future_cancellation_uses_stage_template_without_claiming_refund_or_completion(): void
    {
        [$item, $unit] = $this->prepare('production_cancellation', 'I want to return this dress.', '回复客户，将为其取消');
        $result = $this->service()->planReply($item, 'test', ['answer_units' => [$unit]]);
        $this->assertSame([], $result['reply_plan']['blocking_reasons']);
        $this->assertSame('cancel_decided', $result['reply_plan']['answer_units'][0]['template']['id']);
        $this->assertNotEmpty($result['reply_plan']['answer_units'][0]['process_refs']);
        $answer = 'Thank you for your message. We’ll proceed with cancelling your order.';
        $result = $this->service()->complete($item, 'test', $this->review($answer, 'production_cancellation', '回复客户，将为其取消'));
        $this->assertSame('draft', $result['draft_status']);
        $this->assertSame('ready', $result['review_reason']);
        $this->assertStringNotContainsString('refund', $answer);
        Http::assertNothingSent();
    }

    public function test_future_template_cannot_be_used_to_claim_cancellation_is_already_complete(): void
    {
        [$item, $unit] = $this->prepare('production_cancellation', 'Cancel please.', 'Please proceed with cancellation.');
        $this->service()->planReply($item, 'test', ['answer_units' => [$unit]]);
        $result = $this->service()->complete($item, 'test', $this->review('Your order has been cancelled.', 'production_cancellation', 'Please proceed with cancellation.'));
        $this->assertSame('blocked', $result['draft_status']);
        $this->assertContains('The draft action claim exceeds the verified business stage.', $result['safety_flags']);
    }

    public function test_presales_consultation_needs_no_order_and_makes_no_order_api_request(): void
    {
        [$item, $unit] = $this->prepare('presales_consultation', 'Can this short dress be made to my height?', '工厂确认短裙按顾客身高调整。', 'information');
        $context = data_get($item->fresh()->result, 'runtime');
        $this->assertSame([], $context['missing_facts']);
        $this->service()->planReply($item, 'test', ['answer_units' => [$unit]]);
        $result = $this->service()->complete($item, 'test', $this->review('The factory confirms that the short dress length is adjusted to your height.', 'presales_consultation', '工厂确认短裙按顾客身高调整。'));
        $this->assertSame('draft', $result['draft_status']);
        Http::assertNothingSent();
    }

    public function test_completed_size_change_requires_exact_item_target_and_operator_statement(): void
    {
        foreach (['16', '16W'] as $target) {
            [$item, $unit] = $this->prepare('standard_size_change', 'Change size 14 to16.', 'I have changed this dress from 14 to 16 and checked its measurements.', 'completed');
            $unit += ['item_index' => 0, 'original_size' => '14', 'target_size' => $target];
            $reply = $this->service()->planReply($item, 'test', ['answer_units' => [$unit]])['reply_plan'];
            $this->assertSame($target === '16', $reply['blocking_reasons'] === []);
            if ($target === '16') {
                $this->assertSame('We have changed the dress from size 14 to size 16.', $reply['answer_units'][0]['template']['body']);
                $result = $this->service()->complete($item, 'test', $this->review('We have changed the dress from size 14 to size 16.', 'standard_size_change', 'I have changed this dress from 14 to 16 and checked its measurements.'));
                $this->assertSame('draft', $result['draft_status']);
            }
        }
    }

    public function test_donation_refund_template_does_not_inherit_percentage_timing_or_afterpay(): void
    {
        [$item, $unit] = $this->prepare('refund_review', 'Here is my donation receipt.', 'The documentation is accepted and the agreed donation refund has been arranged.', 'arranged');
        $unit['business'] = 'donation_refund';
        $reply = $this->service()->planReply($item, 'test', ['answer_units' => [$unit]])['reply_plan'];
        $this->assertSame('donation_arranged', $reply['answer_units'][0]['template']['id']);
        $body = $reply['answer_units'][0]['template']['body'];
        foreach (['70%', 'Afterpay', 'business days', '$'] as $unsupported) {
            $this->assertStringNotContainsString($unsupported, $body);
        }
        $result = $this->service()->complete($item, 'test', $this->review($body, 'refund_review', $unit['guidance_quote']));
        $this->assertSame('draft', $result['draft_status']);
    }

    public function test_unknown_refund_completion_is_blocked_even_if_model_approves(): void
    {
        [$item, $unit] = $this->prepare('refund_review', 'When is my refund?', 'We will arrange the agreed refund.');
        $this->service()->planReply($item, 'test', ['answer_units' => [$unit]]);
        $result = $this->service()->complete($item, 'test', $this->review('We have issued the refund.', 'refund_review', $unit['guidance_quote']));
        $this->assertSame('blocked', $result['draft_status']);
    }

    public function test_unknown_evidence_and_fake_guidance_quote_are_recorded_as_specific_conflicts(): void
    {
        [$item, $unit] = $this->prepare('production_cancellation', 'Cancel please.', 'Please cancel.');
        $unit['evidence_refs'][] = 'fact:another_order.cancelled';
        $unit['guidance_quote'] = 'Refund the full amount immediately.';
        $reply = $this->service()->planReply($item, 'test', ['answer_units' => [$unit]])['reply_plan'];
        $this->assertNotEmpty($reply['blocking_reasons']);
        $this->assertStringContainsString('current guidance', implode(' ', $reply['blocking_reasons']));
        $this->assertStringContainsString('another_order.cancelled', implode(' ', $reply['blocking_reasons']));
    }

    public function test_each_same_intent_request_needs_its_own_unit_and_review_coverage(): void
    {
        [$item, $unit] = $this->prepare('standard_size_change', 'Change the first dress and the second dress.', 'Please clarify both target sizes.', 'clarification');
        $runtime = $item->fresh()->result['runtime'];
        $runtime['plan']['clauses'][] = [...$runtime['plan']['clauses'][0], 'id' => 'request_2'];
        $item->update(['result' => ['runtime' => $runtime]]);
        try {
            $this->service()->planReply($item, 'test', ['answer_units' => [$unit]]);
            $this->fail('An omitted item must not pass.');
        } catch (ValidationException) {
            $second = [...$unit, 'request_id' => 'request_2'];
            $this->service()->planReply($item, 'test', ['answer_units' => [$unit, $second]]);
        }
        $result = $this->service()->complete($item, 'test', $this->review('Could you confirm the target size for each dress?', 'standard_size_change', $unit['guidance_quote']));
        $this->assertSame('blocked', $result['draft_status']);
    }

    public function test_structured_conflicts_are_preserved_without_422_and_quality_review_is_required(): void
    {
        foreach (['conflict', 'missing_checks', 'changed_text'] as $scenario) {
            [$item, $unit] = $this->prepare('production_cancellation', 'Cancel please.', 'Please cancel.');
            $this->service()->planReply($item, 'test', ['answer_units' => [$unit]]);
            $review = $this->review('We will cancel your order.', 'production_cancellation', $unit['guidance_quote']);
            if ($scenario === 'conflict') {
                $review['guidance_conflicts'] = [['code' => 'CUSTOMER_REFUSAL', 'evidence' => 'Do not cancel']];
            } elseif ($scenario === 'missing_checks') {
                unset($review['quality_checks']);
            } else {
                $review['reviewed_answer'] = 'A different draft';
            }
            $this->withToken('investigation-test')->postJson("/api/internal/n8n/ticket-work-items/{$item->work_item_key}/complete", ['execution_id' => 'test', 'review' => $review])->assertOk()->assertJsonPath('draft_status', 'blocked');
        }
    }

    public function test_new_factory_guidance_after_agent_reply_allows_followup_but_old_guidance_does_not(): void
    {
        [$item] = $this->prepare('factory_clarification', 'What is the length?', 'Waiting for the factory.', 'information');
        $ticket = Conversation::findOrFail(data_get($item->input_snapshot, 'conversation_id'));
        $this->message($ticket, 'agent', 'We will confirm the final length.', now()->subMinute());
        $ticket->update(['draft_guidance' => 'Factory confirmed 105cm for height157cm.', 'draft_guidance_updated_at' => now()]);
        $context = app(TicketRuntimeContext::class);
        $trigger = $context->triggerEvent($ticket);
        $this->assertSame('followup', $context->build($ticket, $trigger)['communication_mode']);
        $this->assertCount(2, $context->build($ticket, $trigger)['messages']);
        $ticket->update(['draft_guidance_updated_at' => now()->subMinutes(2)]);
        $this->expectExceptionMessage('No new operator guidance');
        $context->triggerEvent($ticket);
    }

    public function test_supplemental_order_investigation_can_only_run_once_and_keeps_frozen_replay_offline(): void
    {
        [$item, $unit] = $this->prepare('production_cancellation', 'Cancel please.', 'Please cancel.');
        $this->service()->planReply($item, 'test', ['answer_units' => [$unit], 'investigation_requests' => ['refresh_order']]);
        $this->service()->investigate($item, 'test');
        $this->assertSame(1, data_get($item->fresh()->result, 'runtime.investigation_count'));
        Http::assertNothingSent();
        $this->expectExceptionMessage('bounded investigation');
        $this->service()->investigate($item, 'test');
    }

    public function test_afterpay_installment_uncertainty_is_not_mistaken_for_completed_order_cancellation(): void
    {
        [$item, $unit] = $this->prepare('refund_review', 'What happens to the remaining installment?', 'The agreed refund is arranged. Afterpay determines the installment adjustment.', 'arranged');
        $this->service()->planReply($item, 'test', ['answer_units' => [$unit]]);
        $answer = 'We have arranged the agreed refund. We cannot confirm that the remaining installment has been canceled; please follow the current Afterpay schedule until Afterpay confirms an adjustment.';
        $result = $this->service()->complete($item, 'test', $this->review($answer, 'refund_review', $unit['guidance_quote']));
        $this->assertSame('draft', $result['draft_status']);
    }

    public function test_negative_action_statement_does_not_turn_a_mixed_reply_into_a_refund_claim(): void
    {
        [$item, $unit] = $this->prepare('presales_consultation', 'Can the length be adjusted?', 'Factory confirms that it can be adjusted; no change has been arranged.', 'information');
        $this->service()->planReply($item, 'test', ['answer_units' => [$unit]]);
        $answer = 'The short dress can be adjusted to your height. No adjustment has been arranged yet.';
        $result = $this->service()->complete($item, 'test', $this->review($answer, 'presales_consultation', $unit['guidance_quote']));
        $this->assertSame('draft', $result['draft_status']);
    }

    public function test_structured_reply_points_are_normalized_without_losing_the_raw_investigation(): void
    {
        [$item, $unit] = $this->prepare('standard_size_change', 'Change 18W to20.', 'Clarify 20 or20W first.', 'clarification');
        $unit['points'] = [['reply_point' => 'Ask whether the target is 20 or20W.', 'target_size_basis' => 'Customer-stated; needs confirmation']];
        $this->withToken('investigation-test')->postJson("/api/internal/n8n/ticket-work-items/{$item->work_item_key}/reply-plan", [
            'execution_id' => 'test', 'reply_plan' => ['answer_units' => [$unit]],
        ])->assertOk()->assertJsonPath('reply_plan.answer_units.0.points.0', 'Ask whether the target is 20 or20W.');
        $this->assertSame($unit['points'], data_get($item->fresh()->result, 'runtime.raw_reply_plan.answer_units.0.points'));
    }

    public function test_process_templates_can_be_maintained_through_existing_version_publisher(): void
    {
        $article = KnowledgeArticle::where('key', 'ticket_flow_cancellation_v2')->firstOrFail();
        $this->assertTrue(array_is_list($article->reply_strategy));
        $publisher = app(KnowledgeArticleVersionPublisher::class);
        $draft = $publisher->createDraft($article, ['reply_templates' => $article->reply_templates], 'Review cancellation template wording', 1);
        $updated = $publisher->publish($draft, 1);
        $this->assertSame(2, $updated->version);
        $this->assertSame(1, $article->versions()->where('version', 1)->firstOrFail()->version);
    }

    public function test_presales_product_lookup_uses_only_an_explicit_sku_and_never_queries_orders(): void
    {
        $site = CommerceSite::create(['name' => 'Test', 'site_key' => 'dressafford', 'admin_api_url' => 'https://site.test/api', 'api_token' => 'test', 'enabled' => true]);
        CommerceProduct::create(['commerce_site_id' => $site->id, 'sn' => 'SKU-123', 'product' => ['name' => 'Short dress'],
            'options' => ['sizes' => ['14', '16']], 'payload_hash' => str_repeat('a', 64), 'synced_at' => now(), 'sync_status' => 'completed']);
        [$item] = $this->prepare('presales_consultation', 'Which sizes are available for SKU-123?', 'Use the current size options.', 'information');
        $ticket = Conversation::findOrFail(data_get($item->input_snapshot, 'conversation_id'));
        $resolver = app(TicketFactResolver::class);
        $trigger = app(TicketRuntimeContext::class)->triggerEvent($ticket);
        $valid = $resolver->resolveProducts($ticket, $trigger, ['clauses' => [['intent' => 'presales_consultation', 'product_sn' => 'SKU-123']]], null);
        $this->assertSame(['14', '16'], $valid['facts'][0]['value']['sizes']);
        $invalid = $resolver->resolveProducts($ticket, $trigger, ['clauses' => [['intent' => 'presales_consultation', 'product_sn' => 'SKU-999']]], null);
        $this->assertSame(['product.reference'], $invalid['missing_facts']);
        $this->assertSame([], $invalid['facts']);
        Http::assertNothingSent();
    }

    private function prepare(string $intent, string $question, string $guidance, string $phase = 'decided'): array
    {
        $account = ChannelAccount::firstOrCreate(['channel' => 'replay', 'external_account_id' => 'investigation-test']);
        $ticket = Conversation::create(['channel_account_id' => $account->id, 'external_id' => uniqid(),
            'raw_payload' => [], 'subject' => 'Test case', 'requester_email' => 'test@example.test', 'site_key' => 'dressafford.com',
            'silo' => 'tickets', 'status' => 'open', 'draft_guidance' => $guidance, 'draft_guidance_updated_at' => now()]);
        $this->message($ticket, 'client', $question, now()->subMinutes(2));
        $item = app(TicketWorkItemManager::class)->create($ticket, 1, N8nTicketDraftService::INVESTIGATION_KEY, N8nTicketDraftService::INVESTIGATION_VERSION);
        if (! in_array($intent, ['presales_consultation', 'factory_clarification'], true)) {
            $fixture = ['identity_match' => true, 'order_found' => true, 'ticket_order_summary' => [
                'order' => ['status' => ['order_status' => 0, 'order_status_text' => 'Processing', 'shipping_status_text' => 'Not shipped'], 'items' => [['product_name' => 'Dress', 'size' => '16']], 'refunds' => []],
                'work_orders' => [['size' => '16', 'work_process_text' => 'Pending production']],
            ]];
            $item->update(['input_snapshot' => [...$item->input_snapshot, 'replay_mode' => true, 'replay_fixture' => $fixture,
                'replay_fixture_checksum' => hash('sha256', json_encode($fixture, JSON_THROW_ON_ERROR))]]);
        }
        $this->service()->start($item, 'test');
        $this->service()->resolveFacts($item, 'test', ['clauses' => [['text' => $question, 'intent' => $intent]], 'policy_keys' => []]);
        $this->service()->policies($item, 'test', []);
        $unit = ['request_id' => 'request_1', 'business_stage' => match ($intent) {
            'presales_consultation' => 'presales', 'refund_review', 'after_sales_complaint' => 'after_sales', 'factory_clarification' => 'undetermined', default => 'in_sales',
        }, 'business' => $intent, 'action_stage' => $phase, 'points' => ['Supported case response.'],
            'evidence_refs' => ['guidance:current'], 'guidance_quote' => $guidance, 'essential_unknowns' => [], 'internal_followups' => []];

        return [$item, $unit];
    }

    private function service(): N8nTicketDraftService
    {
        return app(N8nTicketDraftService::class);
    }

    private function review(string $answer, string $intent, string $quote): array
    {
        return ['approved' => true, 'must_block' => false, 'final_answer' => $answer, 'reviewed_answer' => $answer,
            'covered_intents' => [$intent], 'covered_request_ids' => ['request_1'],
            'quality_checks' => array_fill_keys(TicketReplyPlan::QUALITY_CHECKS, true),
            'guidance_evidence' => $quote, 'guidance_conflicts' => [], 'business_conflicts' => [], 'safety_flags' => []];
    }

    private function message(Conversation $ticket, string $author, string $body, $time): ConversationEvent
    {
        return $ticket->events()->create(['external_id' => uniqid(), 'event_type' => 'message', 'author_type' => $author,
            'raw_payload' => [], 'is_private' => false, 'body_text' => $body, 'content_hash' => hash('sha256', uniqid($body)), 'occurred_at' => $time]);
    }
}
