<?php

namespace App\Services\TicketWork;

use App\Models\Conversation;
use App\Models\ConversationDraft;
use App\Models\ConversationEvent;
use App\Models\WorkItem;
use App\Services\Knowledge\WeKnoraKnowledgeRetriever;
use App\Services\N8n\WorkflowRegistry;
use App\Services\Work\WorkItemLeaseService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class N8nTicketDraftService
{
    public const WORKFLOW_KEY = 'work/ticket-draft-v1';

    public const WORKFLOW_VERSION = '1.0.0';

    public const INVESTIGATION_KEY = 'work/ticket-draft-v2';

    public const INVESTIGATION_VERSION = '2.0.0';

    public function __construct(
        private readonly TicketDraftInput $input,
        private readonly TicketRuntimeContext $context,
        private readonly TicketDraftPlanGuard $plans,
        private readonly TicketFactResolver $facts,
        private readonly TicketPolicyRepository $policies,
        private readonly WorkflowRegistry $workflows,
        private readonly WorkItemLeaseService $leases,
    ) {}

    /** @return array<string,mixed> */
    public function start(WorkItem $item, string $executionId): array
    {
        $workflow = $this->workflows->runnable((string) $item->workflow_key, (string) $item->workflow_version);
        if ($workflow->center !== 'work' || $workflow->domain !== 'tickets') {
            throw new RuntimeException('The workflow is not registered for the Ticket runtime center.');
        }
        if ($item->status === 'completed') {
            return ['already_completed' => true, 'result' => $item->result];
        }
        $item = $this->leases->lease($item, $executionId, $this->isInvestigation($item) ? 1800 : 600);
        $conversation = $this->conversation($item);
        $requestedBy = (int) data_get($item->input_snapshot, 'requested_by');
        $current = $this->input->capture(
            $conversation,
            $requestedBy,
            (string) $item->workflow_key,
            (string) $item->workflow_version,
        );
        if (! hash_equals((string) $item->input_hash, $current['hash'])) {
            $this->stale($item, 'Ticket messages, Analysis, or published Policies changed before execution.');
        }
        $replayFixture = $this->replayFixture($item);
        $catalog = $this->policies->available($conversation->site_key);
        if (is_array($replayFixture['available_policy_keys'] ?? null)) {
            $catalog = $catalog->whereIn('key', $replayFixture['available_policy_keys'])->values();
        }
        if ($this->isInvestigation($item)) {
            $catalog = $catalog->reject(fn ($article) => $article->key === 'ticket_order_action_review_process')->values();
        }
        $ticketContext = $this->context->build($conversation, $current['trigger'], $this->isInvestigation($item));
        $item->update(['execution_id' => $executionId, 'result' => ['runtime' => $this->isInvestigation($item) ? ['ticket_context' => $ticketContext] : []]]);

        return [
            'work_item_key' => $item->work_item_key,
            'input_hash' => $item->input_hash,
            'ticket' => $ticketContext,
            'order_assessment' => $replayFixture === null ? $this->facts->planningContext($conversation)
                : ($this->isInvestigation($item) ? $this->facts->planningFixtureContext($replayFixture) : null),
            'operator_guidance' => $conversation->draftGuidance() === null ? null : [...$conversation->draftGuidance(), 'text' => $this->context->redact($conversation->draftGuidance()['text'])],
            'routing_hint' => $conversation->analysis ? [
                'primary_category' => $conversation->analysis->primary_category,
                'primary_problem_type' => $conversation->analysis->primary_problem_type,
                'urgency' => $conversation->analysis->urgency,
            ] : null,
            'supported_intents' => $this->isInvestigation($item) ? TicketDraftPlanGuard::INVESTIGATION_INTENTS : TicketDraftPlanGuard::SUPPORTED_INTENTS,
            'verified_policy_catalog' => $catalog->map(fn ($article): array => [
                'key' => $article->key,
                'title' => $article->title,
                'knowledge_type' => $article->knowledge_type,
                'applicability' => $article->applicability,
                'automation_tier' => $article->automation_tier,
            ])->values()->all(),
            'allowed_actions' => $workflow->allowed_actions ?? [],
            'reply_style' => [
                'concise' => true,
                'warm_customer_service_voice' => true,
                'maximum_words' => $this->isInvestigation($item) ? 600 : 180,
                'answer_every_supported_clause' => true,
                'do_not_invent_facts' => true,
                'do_not_promise_future_actions' => ! $this->isInvestigation($item),
                'do_not_disclose_private_identifiers' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function resolveFacts(WorkItem $item, string $executionId, array $plan): array
    {
        $this->assertLease($item, $executionId);
        $plan = $this->plans->validate($plan, $this->isInvestigation($item));
        $plan['policy_keys'] = array_values(array_unique([
            ...$plan['policy_keys'],
            ...$this->policies->baselineKeys($plan['intents']),
        ]));
        $conversation = $this->conversation($item);
        $trigger = $this->trigger($conversation, $item);
        if ($this->isInvestigation($item) && $this->replayFixture($item) === null) {
            $plan['policy_keys'] = array_slice(array_values(array_unique([...$plan['policy_keys'],
                ...app(WeKnoraKnowledgeRetriever::class)->keys($conversation, $plan)])), 0, 8);
        }
        $before = $this->input->capture($conversation, (int) data_get($item->input_snapshot, 'requested_by'), (string) $item->workflow_key, (string) $item->workflow_version);
        if (! hash_equals((string) $item->input_hash, $before['hash'])) {
            $this->stale($item, 'Ticket inputs changed before fact resolution.');
        }
        $resolved = $this->isInvestigation($item)
            ? $this->facts->resolveInvestigation($conversation, $trigger, $plan, $this->replayFixture($item))
            : $this->facts->resolve($conversation, $trigger, $plan, $this->replayFixture($item));
        if ($this->isInvestigation($item)) {
            $products = $this->facts->resolveProducts($conversation, $trigger, $plan, $this->replayFixture($item));
            foreach (['facts', 'missing_facts', 'safety_flags'] as $key) {
                $resolved[$key] = [...$resolved[$key], ...$products[$key]];
            }
        }
        if ($this->isInvestigation($item) && $this->replayFixture($item) === null) {
            $captured = $this->input->capture($conversation->refresh(), (int) data_get($item->input_snapshot, 'requested_by'), (string) $item->workflow_key, (string) $item->workflow_version);
            if (Arr::except($before['snapshot'], ['order_context', 'site_key', 'linked_order_number']) !== Arr::except($captured['snapshot'], ['order_context', 'site_key', 'linked_order_number'])) {
                $this->stale($item, 'Ticket inputs changed during fact resolution.');
            }
            $item->update(['input_hash' => $captured['hash'], 'input_snapshot' => [...$item->input_snapshot, 'resolved_input' => $captured['snapshot']]]);
        }
        $runtime = (array) data_get($item->result, 'runtime', []);
        $item->update(['result' => ['runtime' => [
            ...$runtime,
            'plan' => $plan,
            ...$resolved,
            'order_context_id' => $conversation->latestOrderContext()->value('id'),
            'order_context_hash' => $conversation->latestOrderContext()->value('response_hash'),
        ]]]);

        return ['plan' => $plan, ...$resolved];
    }

    /** @param list<string> $keys @return array{data:list<array<string,mixed>>} */
    public function policies(WorkItem $item, string $executionId, array $keys): array
    {
        $this->assertLease($item, $executionId);
        $conversation = $this->conversation($item);
        $runtime = (array) data_get($item->result, 'runtime', []);
        $plannedKeys = array_values(array_filter((array) data_get($runtime, 'plan.policy_keys', []), 'is_string'));
        if ($this->isInvestigation($item)) {
            $processKeys = $this->policies->available($conversation->site_key)->filter(fn ($article) => $article->knowledge_type === 'process'
                && in_array(data_get($article->reply_strategy, '0.intent'), $runtime['plan']['intents'] ?? [], true))->pluck('key')->all();
            if (in_array('refund_review', $runtime['plan']['intents'] ?? [], true)) {
                $processKeys = [...$processKeys, 'ticket_flow_cancellation_v2'];
            }
            $plannedKeys = array_values(array_unique([...$plannedKeys, ...$processKeys]));
            $keys = array_values(array_unique([...$keys, ...$processKeys]));
        }
        $selected = $this->policies->selected($conversation->site_key, array_values(array_intersect($keys, $plannedKeys)));
        $replayFixture = $this->replayFixture($item);
        if (is_array($replayFixture['available_policy_keys'] ?? null)) {
            $selected = $selected->whereIn('key', $replayFixture['available_policy_keys'])->values();
        }
        if ($this->isInvestigation($item)) {
            $selected = $selected->reject(fn ($article) => $article->key === 'ticket_order_action_review_process')->values();
        }
        $data = $selected->map(fn ($article): array => $this->policies->payload($article))->values()->all();
        $item->update(['result' => ['runtime' => [...$runtime, 'policies' => $data]]]);

        return ['data' => $data];
    }

    public function planReply(WorkItem $item, string $executionId, array $reply): array
    {
        $this->assertLease($item, $executionId);
        if (! $this->isInvestigation($item)) {
            throw new RuntimeException('Reply planning requires the investigation workflow.');
        }
        $runtime = (array) data_get($item->result, 'runtime', []);
        $guidance = $this->conversation($item)->draftGuidance();
        if ($guidance !== null) {
            $guidance['text'] = $this->context->redact($guidance['text']);
        }
        $rawReply = $reply;
        $reply = app(TicketResponseAssessment::class)->validate($reply, $runtime,
            $this->context->build($this->conversation($item), $this->trigger($this->conversation($item), $item), true));
        $reply = app(TicketReplyPlan::class)->validate($reply, $runtime, $guidance);
        $item->update(['result' => ['runtime' => [...$runtime, 'reply_plan' => $reply, 'raw_reply_plan' => $rawReply]]]);

        return ['reply_plan' => $reply, 'can_investigate' => ($runtime['investigation_count'] ?? 0) < 1
            && $reply['investigation_requests'] !== []];
    }

    public function investigate(WorkItem $item, string $executionId): array
    {
        $this->assertLease($item, $executionId);
        $runtime = (array) data_get($item->result, 'runtime', []);
        if (! $this->isInvestigation($item) || ($runtime['investigation_count'] ?? 0) >= 1
            || ! in_array('refresh_order', data_get($runtime, 'reply_plan.investigation_requests', []), true)) {
            throw new RuntimeException('The bounded investigation is not available.');
        }
        $runtime['investigation_count'] = 1;
        $runtime['initial_investigation'] = Arr::only($runtime, ['plan', 'facts', 'missing_facts', 'reply_plan', 'order_context_id', 'order_context_hash']);
        $item->update(['result' => ['runtime' => $runtime]]);
        $conversation = $this->conversation($item);
        if ($this->replayFixture($item) === null) {
            // Refuse to change the frozen input if a human/message/policy changed concurrently.
            $current = $this->input->capture($conversation, (int) data_get($item->input_snapshot, 'requested_by'), (string) $item->workflow_key, (string) $item->workflow_version);
            if (! hash_equals((string) $item->input_hash, $current['hash'])) {
                $this->stale($item, 'Ticket inputs changed before supplemental investigation.');
            }
            app(TicketOrderWorkbench::class)->inspect($conversation);
            $current = $this->input->capture($conversation->refresh(), (int) data_get($item->input_snapshot, 'requested_by'), (string) $item->workflow_key, (string) $item->workflow_version);
            // Preserve the original snapshot for audit; the supplement is one explicit new evidence boundary.
            $item->update(['input_hash' => $current['hash'], 'input_snapshot' => [...$item->input_snapshot,
                'supplemental_input' => $current['snapshot']]]);
        }
        $resolved = $this->resolveFacts($item, $executionId, $runtime['plan']);

        return [...$resolved, 'policies' => $runtime['policies'] ?? []];
    }

    private function isInvestigation(WorkItem $item): bool
    {
        return $item->workflow_key === self::INVESTIGATION_KEY && $item->workflow_version === self::INVESTIGATION_VERSION;
    }

    /** @return array<string,mixed> */
    public function complete(WorkItem $item, string $executionId, array $review): array
    {
        $this->assertLease($item, $executionId);
        $conversation = $this->conversation($item);
        $current = $this->input->capture(
            $conversation,
            (int) data_get($item->input_snapshot, 'requested_by'),
            (string) $item->workflow_key,
            (string) $item->workflow_version,
        );
        if (! hash_equals((string) $item->input_hash, $current['hash'])) {
            $this->stale($item, 'Ticket messages, Analysis, or published Policies changed during execution.');
        }
        $runtime = (array) data_get($item->result, 'runtime', []);
        $investigation = $this->isInvestigation($item);
        $plan = $this->plans->validate((array) ($runtime['plan'] ?? []), $investigation);
        $facts = array_values((array) ($runtime['facts'] ?? []));
        $missingFacts = array_values(array_filter((array) ($runtime['missing_facts'] ?? []), 'is_string'));
        $policies = array_values((array) ($runtime['policies'] ?? []));
        $answer = trim((string) ($review['final_answer'] ?? $review['answer_customer_language'] ?? $review['answer_en'] ?? ''));
        $coveredIntents = array_values(array_filter((array) ($review['covered_intents'] ?? []), 'is_string'));
        $safetyFlags = array_values(array_unique(array_filter([
            ...((array) ($runtime['safety_flags'] ?? [])),
            ...((array) ($review['safety_flags'] ?? [])),
            ...($investigation ? [] : $this->plans->blockingReasons($plan, $missingFacts, count($policies))),
        ], 'is_string')));
        $approved = ($review['approved'] ?? false) === true
            && ($review['must_block'] ?? true) === false
            && array_diff($plan['intents'], $coveredIntents) === []
            && $answer !== '';
        if ($this->containsPrivateIdentifier($answer)) {
            $approved = false;
            $safetyFlags[] = 'The Draft contains a private email, phone, or order identifier.';
        }
        if ($this->containsRequesterName($answer, $conversation)) {
            $approved = false;
            $safetyFlags[] = 'The Draft unnecessarily repeats the customer name.';
        }
        if (! $investigation && $this->promisesUnsupportedAction($answer)) {
            $approved = false;
            $safetyFlags[] = 'The Draft promises a future human, factory, or carrier action that was not executed.';
        }
        if (! $investigation && $this->plans->blockingReasons($plan, $missingFacts, count($policies)) !== []) {
            $approved = false;
        }
        if ($answer === '') {
            $safetyFlags[] = 'The workflow did not return a usable Draft.';
        }
        if (str_word_count($answer) > ($investigation ? 600 : 180)) {
            $approved = false;
            $safetyFlags[] = 'The Draft exceeds the runtime word limit.';
        }
        if (array_diff($plan['intents'], $coveredIntents) !== []) {
            $safetyFlags[] = 'The Draft does not cover every planned supported intent.';
        }
        $guidance = $conversation->draftGuidance();
        if ($guidance !== null && (($review['guidance_conflicts'] ?? null) !== []
            || ! is_string($review['guidance_evidence'] ?? null)
            || ! str_contains($this->context->redact($guidance['text']), trim($review['guidance_evidence']))
            || trim($review['guidance_evidence']) === '')) {
            $approved = false;
            $safetyFlags[] = $investigation && ($review['guidance_conflicts'] ?? []) !== []
                ? 'Current-ticket guidance has an unresolved decision or evidence conflict.'
                : 'Current-ticket guidance lacks an independent conflict check and exact supporting quote.';
        }
        $actionIntents = array_intersect($plan['intents'], ['production_cancellation', 'standard_size_change', 'refund_review']);
        if ($actionIntents !== [] || ($investigation && array_intersect($plan['intents'], ['production_order_status', 'logistics_tracking_delivery', 'after_sales_complaint']) !== [])) {
            if ($this->replayFixture($item) === null) {
                $snapshot = $conversation->latestOrderContext()->first();
                if ($snapshot === null || ! app(TicketOrderWorkbench::class)->isFresh($conversation, $snapshot)
                    || ($investigation ? $snapshot->response_hash !== ($runtime['order_context_hash'] ?? null) : $snapshot->id !== ($runtime['order_context_id'] ?? null))) {
                    $approved = false;
                    $safetyFlags[] = 'Order action assessment changed or expired during drafting.';
                }
            }
            $boundIntents = collect($policies)->flatMap(fn ($p) => $p['bound_intents'] ?? [])->all();
            if (! $investigation && array_diff($actionIntents, $boundIntents) !== []) {
                $approved = false;
                $safetyFlags[] = 'An action intent lacks an explicitly bound verified Policy.';
            }
            $cancelled = collect($facts)->firstWhere('key', 'order.cancelled')['value'] ?? false;
            if (! $investigation && ((! $cancelled && preg_match('/\b(?:has been|is now|is|was|have|we)\s+(?:cancelled|canceled)\b/i', $answer))
                || preg_match('/\b(?:we have|we.ve|has been)\s+(?:changed|updated|refunded|processed|issued|arranged)\b|\brefund\s+(?:has been|was)\s+(?:issued|processed|arranged)\b/i', $answer))) {
                $approved = false;
                $safetyFlags[] = 'The Draft claims an unverified business action.';
            }
        }
        if ($investigation) {
            $reply = (array) ($runtime['reply_plan'] ?? []);
            $checks = (array) ($review['quality_checks'] ?? []);
            $covered = (array) ($review['covered_request_ids'] ?? []);
            $reasons = (array) ($reply['blocking_reasons'] ?? []);
            if (($reply['version'] ?? '') !== TicketReplyPlan::VERSION
                || array_diff(array_column($plan['clauses'], 'id'), $covered) !== []
                || collect(TicketReplyPlan::QUALITY_CHECKS)->contains(fn ($key) => ($checks[$key] ?? null) !== true)
                || ($review['reviewed_answer'] ?? '') !== $answer
                || ($review['business_conflicts'] ?? null) !== [] || $reasons !== []) {
                $approved = false;
                $safetyFlags[] = 'The investigation plan, request coverage, independent review, or evidence checks are incomplete.';
            }
            foreach ($facts as $fact) {
                if (isset($fact['fresh_until']) && now()->gt($fact['fresh_until'])) {
                    $approved = false;
                    $safetyFlags[] = 'A fact expired during drafting.';
                }
            }
            $units = collect($reply['answer_units'] ?? []);
            $values = collect($facts)->keyBy('key');
            // Check positive business claims only; "no adjustment has been arranged" is not a refund claim.
            $claimText = preg_replace('/\b(?:has|have|is|was)\s+not\s+(?:been\s+)?(?:cancelled|canceled|arranged|issued|processed|changed|updated|refunded)\b|\bno\s+(?:\w+\s+){0,3}(?:has been|was|is)\s+(?:cancelled|canceled|arranged|issued|processed|changed|updated|refunded)\b/i', '', $answer) ?? $answer;
            $completedCancellation = preg_match('/\b(?:order|purchase)\b[^,.!?\n]{0,40}\b(?:has been|is now|is|was)\s+(?:cancelled|canceled)\b|\bwe(?: have|[’\']ve)?\s+(?:cancelled|canceled)\s+(?:(?:your|the|this)\s+)?order\b/i', $claimText);
            $futureCancellation = preg_match('/\bwe(?:[’\']ll| will)\s+(?:proceed with\s+)?(?:cancell?ing|cancel)\b/i', $claimText);
            $sizeCompletion = preg_match('/\b(?:we have|we[’\']ve)\s+(?:changed|updated)\s+(?:(?:the|your|this)\s+)?(?:dress|size|gown|item)\b|\b(?:dress|gown|size|item)\b[^.!?]{0,40}\bhas been\s+(?:changed|updated)\b/i', $claimText);
            $refundArrangement = preg_match('/\b(?:we have|we[’\']ve)\s+arranged\s+[a-z -]{0,40}\brefund\b|\brefund\s+(?:has been|was)\s+arranged\b/i', $claimText);
            $futureRefund = preg_match('/\bwe(?:[’\']ll| will)\s+(?:proceed with\s+)?(?:refund\b|(?:process|issue|arrange)\s+[a-z -]{0,40}\brefund\b)/i', $claimText);
            $refundCompletion = preg_match('/\b(?:we have|we[’\']ve)\s+(?:refunded\b|(?:processed|issued)\s+[a-z -]{0,40}\brefund\b)|\brefund\s+(?:has been|was)\s+(?:issued|processed)\b/i', $claimText);
            if (($completedCancellation && ($values['order.cancelled']['value'] ?? null) !== true)
                || ($futureCancellation && ! $units->contains(fn ($u) => $u['business'] === 'production_cancellation' && in_array($u['action_stage'], ['decided', 'arranged'], true) && filled($u['guidance_quote'] ?? null)))
                || ($sizeCompletion && ! $units->contains(fn ($u) => $u['intent'] === 'standard_size_change' && $u['action_stage'] === 'completed'))
                || ($refundArrangement && ! $units->contains(fn ($u) => $u['intent'] === 'refund_review' && $u['action_stage'] === 'arranged'))
                || ($futureRefund && ! $units->contains(fn ($u) => $u['intent'] === 'refund_review' && in_array($u['action_stage'], ['decided', 'arranged'], true) && filled($u['guidance_quote'] ?? null)))
                || $refundCompletion) {
                $approved = false;
                $safetyFlags[] = 'The draft action claim exceeds the verified business stage.';
            }
            // Identity and query failures cannot be dismissed by the model as incidental unknowns.
            if (array_intersect($missingFacts, ['order.identity_verified', 'order.action_snapshot', 'order.lookup', 'order.match', 'order.number', 'order.disambiguation']) !== []) {
                $approved = false;
                $safetyFlags[] = 'Required order identity or current lookup is unavailable.';
            }
            if (preg_match('/\{\{[^}]+\}\}|\[(?:ORDER_NUMBER|CUSTOMER_NAME|EMAIL|PHONE)\]/', $answer)) {
                $approved = false;
                $safetyFlags[] = 'The draft contains unresolved template variables.';
            }
            $safetyFlags = [...$safetyFlags, ...$reasons];
        }
        if ($investigation && data_get($runtime, 'reply_plan.response_decision.mode') === 'no_reply') {
            $decisionApproved = ($review['approved'] ?? false) === true && ($review['must_block'] ?? true) === false
                && ($review['response_decision_approved'] ?? false) === true
                && ($review['reviewed_response_mode'] ?? null) === 'no_reply'
                && ($review['business_conflicts'] ?? null) === [] && ($review['guidance_conflicts'] ?? null) === []
                && ($runtime['reply_plan']['blocking_reasons'] ?? []) === []
                && collect(TicketReplyPlan::QUALITY_CHECKS)->every(fn ($key) => ($review['quality_checks'][$key] ?? null) === true)
                && array_diff(array_column($plan['clauses'], 'id'), $review['covered_request_ids'] ?? []) === []
                && $answer === ''
                && ($guidance === null || (is_string($review['guidance_evidence'] ?? null)
                    && trim($review['guidance_evidence']) !== ''
                    && str_contains($this->context->redact($guidance['text']), trim($review['guidance_evidence']))));
            // Recheck the assessment against current evidence, independent of model approval.
            app(TicketResponseAssessment::class)->validate($runtime['reply_plan'], $runtime,
                $this->context->build($conversation, $current['trigger'], true));
            $result = ['schema_version' => '2.0.0', 'draft_id' => null,
                'draft_status' => $decisionApproved ? 'no_reply' : 'needs_review',
                'context_sources' => Arr::only($runtime['ticket_context'] ?? [], ['related_conversations', 'history_incomplete']),
                'reply_plan' => $runtime['reply_plan'], 'plan' => $plan, 'review' => $review,
                'review_reason' => $decisionApproved ? 'no_reply_recommended' : 'response_decision_needs_review',
                'safety_flags' => $decisionApproved ? [] : ['The no-reply recommendation did not pass independent review.'],
                'requires_human_review' => true, 'runtime_audit' => $runtime];
            $this->leases->complete($item, $executionId, $result);

            return $result;
        }
        if ($investigation && isset($runtime['reply_plan']['case_progress'])
            && (($review['response_decision_approved'] ?? null) !== true
                || ($review['reviewed_response_mode'] ?? null) !== data_get($runtime, 'reply_plan.response_decision.mode'))) {
            $approved = false;
            $safetyFlags[] = 'The reviewer did not approve the exact response decision.';
        }
        $draftStatus = $approved ? 'draft' : 'blocked';

        return DB::transaction(function () use (
            $item,
            $executionId,
            $conversation,
            $plan,
            $facts,
            $missingFacts,
            $policies,
            $review,
            $answer,
            $safetyFlags,
            $draftStatus,
            $runtime,
            $actionIntents,
            $approved,
        ): array {
            $draft = ConversationDraft::query()->create([
                'conversation_id' => $conversation->id,
                'work_item_id' => $item->id,
                'body' => $answer,
                'is_private' => false,
                'status_after_send' => null,
                'source' => 'n8n',
                'metadata' => [
                    'workflow_key' => $item->workflow_key,
                    'workflow_version' => $item->workflow_version,
                    'input_hash' => $item->input_hash,
                    'workbench_input_hash' => app(TicketOrderWorkbench::class)->inputHash($conversation),
                    'order_context_id' => $conversation->latestOrderContext()->value('id'),
                    'order_context_hash' => $conversation->latestOrderContext()->value('response_hash'),
                    'requires_order' => $actionIntents !== [] || array_intersect($plan['intents'], ['production_order_status', 'logistics_tracking_delivery', 'after_sales_complaint']) !== [],
                    'reply_plan' => $runtime['reply_plan'] ?? null,
                    'context_sources' => Arr::only($runtime['ticket_context'] ?? [], ['related_conversations', 'history_incomplete']),
                    'runtime_audit' => $runtime,
                    'plan' => $plan,
                    'facts' => $facts,
                    'missing_facts' => $missingFacts,
                    'knowledge_articles' => collect($policies)->map(fn ($policy): array => [
                        'id' => $policy['id'] ?? null,
                        'key' => $policy['key'] ?? null,
                        'version' => $policy['version'] ?? null,
                    ])->values()->all(),
                    'review' => $review,
                    'safety_flags' => array_values(array_unique($safetyFlags)),
                ],
                'requires_human_review' => true,
                'status' => $draftStatus,
                'created_by' => (int) data_get($item->input_snapshot, 'requested_by'),
                'updated_by' => (int) data_get($item->input_snapshot, 'requested_by'),
            ]);
            $result = [
                'schema_version' => '1.0.0',
                'draft_id' => $draft->id,
                'draft_status' => $draftStatus,
                'reply_plan' => $runtime['reply_plan'] ?? null,
                'context_sources' => Arr::only($runtime['ticket_context'] ?? [], ['related_conversations', 'history_incomplete']),
                'review_reason' => $approved ? 'ready' : (($runtime['reply_plan']['blocking_reasons'] ?? []) !== [] ? 'decision_conflict' : 'needs_review'),
                'plan' => $plan,
                'facts' => $facts,
                'missing_facts' => $missingFacts,
                'knowledge_articles' => data_get($draft->metadata, 'knowledge_articles', []),
                'review' => $review,
                'safety_flags' => array_values(array_unique($safetyFlags)),
                'requires_human_review' => true,
            ];
            $this->leases->complete($item, $executionId, $result);

            return $result;
        });
    }

    public function fail(WorkItem $item, string $executionId, string $message): void
    {
        $this->assertLease($item, $executionId);
        $this->leases->fail($item, $executionId, $message);
    }

    private function conversation(WorkItem $item): Conversation
    {
        $source = $item->customerCase()->firstOrFail()->sources()
            ->where('source_type', 'conversation')->firstOrFail();

        return Conversation::query()->with('analysis')->findOrFail((int) $source->source_id);
    }

    private function trigger(Conversation $conversation, WorkItem $item): ConversationEvent
    {
        return $conversation->events()->findOrFail((int) data_get($item->input_snapshot, 'trigger_event_id'));
    }

    private function assertLease(WorkItem $item, string $executionId): void
    {
        $item->refresh();
        if ($item->status !== 'running' || $item->lease_owner !== $executionId
            || $item->lease_expires_at === null || $item->lease_expires_at->isPast()
        ) {
            throw new RuntimeException('The Ticket WorkItem is not leased by this n8n execution.');
        }
    }

    private function stale(WorkItem $item, string $message): never
    {
        $item->update([
            'status' => 'stale',
            'error_message' => $message,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'finished_at' => now(),
        ]);

        throw new RuntimeException($message);
    }

    /** @return array<string,mixed>|null */
    private function replayFixture(WorkItem $item): ?array
    {
        if (data_get($item->input_snapshot, 'replay_mode') !== true) {
            return null;
        }
        $fixture = data_get($item->input_snapshot, 'replay_fixture');
        $checksum = data_get($item->input_snapshot, 'replay_fixture_checksum');
        if (! is_array($fixture) || ! is_string($checksum)
            || ! hash_equals($checksum, hash('sha256', json_encode($fixture, JSON_THROW_ON_ERROR)))
        ) {
            throw new RuntimeException('Ticket replay fixture checksum is invalid.');
        }

        return $fixture;
    }

    private function containsPrivateIdentifier(string $answer): bool
    {
        return preg_match('/\b[a-z]{2,12}\d{8}-\d{3,12}\b/i', $answer) === 1
            || preg_match('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', $answer) === 1
            || $this->containsPhoneNumber($answer);
    }

    private function promisesUnsupportedAction(string $answer): bool
    {
        return preg_match('/\b(?:we|our (?:team|support|factory))\s+(?:will|can)\s+(?:check|verify|contact|follow up|escalate|investigate|get back|ask)\b/i', $answer) === 1
            || preg_match('/\bwill be\s+(?:checked|confirmed|reviewed|escalated|noted|forwarded)\b|\bhas been\s+(?:escalated|forwarded)\b/i', $answer) === 1;
    }

    private function containsPhoneNumber(string $answer): bool
    {
        preg_match_all('/(?<!\w)(?:\+?\d[\d\s().-]{7,}\d)(?!\w)/', $answer, $matches);

        return collect($matches[0] ?? [])->contains(function (string $candidate): bool {
            $digits = preg_replace('/\D/', '', $candidate) ?? '';

            return str_starts_with(trim($candidate), '+') ? strlen($digits) >= 8 : strlen($digits) >= 10;
        });
    }

    private function containsRequesterName(string $answer, Conversation $conversation): bool
    {
        return collect([
            data_get($conversation->requester_snapshot, 'name'),
            data_get($conversation->requester_snapshot, 'first_name'),
            data_get($conversation->requester_snapshot, 'last_name'),
            data_get($conversation->requester_snapshot, 'display_name'),
        ])->filter(fn ($name): bool => is_string($name) && mb_strlen(trim($name)) >= 2)
            ->contains(fn ($name): bool => mb_stripos($answer, trim($name)) !== false);
    }
}
