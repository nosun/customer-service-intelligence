<?php

namespace App\Services\ProductQuestions;

use App\Models\KnowledgeArticle;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionDraft;
use App\Models\ProductQuestionDraftRequest;
use App\Services\AI\DraftCompletionClient;
use App\Services\Knowledge\KnowledgeResolver;
use App\Services\N8n\WorkflowRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class N8nProductQuestionDraftService
{
    public const REPLY_STYLE_VERSION = 'decision-v11-service-strategy';

    public const WORKFLOW_KEY = 'work/qa-draft-agent-v1';

    public const WORKFLOW_VERSION = '1.1.0';

    public const PROMPT_VERSION = 'n8n-qa-draft-agent-v3';

    public function __construct(
        private readonly ProductQuestionFactResolver $facts,
        private readonly ProductQuestionFactContract $contract,
        private readonly ProductQuestionDraftPlanGuard $planGuard,
        private readonly ProductQuestionDraftInput $input,
        private readonly WorkflowRegistry $workflows,
        private readonly KnowledgeResolver $knowledge,
    ) {}

    /** @return array<string, mixed> */
    public function start(ProductQuestionDraftRequest $request, string $executionId): array
    {
        $workflow = $this->workflows->runnable($request->workflow_key, $request->workflow_version);
        $request->loadMissing('question.site', 'question.analysis');
        if ($request->status === 'completed') {
            return ['already_completed' => true, 'draft_id' => $request->product_question_draft_id];
        }
        if ($request->status === 'running' && $request->n8n_execution_id !== $executionId) {
            throw new RuntimeException('Draft request is already owned by another n8n execution.');
        }
        if (! in_array($request->status, ['queued', 'running'], true)) {
            throw new RuntimeException('Draft request cannot be started from its current status.');
        }
        $currentInput = $this->input->capture(
            $request->question,
            $request->workflow_key,
            $request->workflow_version,
            $workflow->prompt_version ?: self::PROMPT_VERSION,
        );
        if ($request->input_hash === null) {
            $request->update(['input_hash' => $currentInput['hash'], 'input_snapshot' => $currentInput['snapshot']]);
            $request->refresh();
        }
        if ($request->input_hash !== $currentInput['hash']) {
            $request->update([
                'status' => 'stale',
                'error_message' => 'Draft input changed before execution started.',
                'finished_at' => now(),
            ]);
            throw new RuntimeException('Draft request input is stale; create a new request.');
        }
        $request->update([
            'status' => 'running',
            'n8n_execution_id' => $executionId,
            'attempt_count' => $request->attempt_count + 1,
            'started_at' => $request->started_at ?? now(),
            'error_message' => null,
        ]);
        $question = $request->question;
        $policies = $this->availablePolicies($question);

        return [
            'request_id' => $request->request_id,
            'input_hash' => $request->input_hash,
            'question_id' => $question->id,
            'external_question_id' => $question->external_id,
            'site_key' => $question->site->site_key,
            'question' => $this->redact(trim($question->question)),
            'product_sn' => $question->sn,
            'question_created_at' => $question->remote_created_at?->toIso8601String(),
            'current_date' => now()->toDateString(),
            'existing_analysis' => $question->analysis ? [
                'intent_key' => $question->analysis->intent_key,
                'customer_intent' => $question->analysis->customer_intent,
                'secondary_categories' => $question->analysis->secondary_categories,
                'risk_level' => $question->analysis->risk_level,
            ] : null,
            'available_policies' => $policies->map(fn (KnowledgeArticle $article): array => [
                'id' => $article->id,
                'intent' => $this->intent($article),
                'title' => $article->title,
                'applicability' => $article->applicability,
                'automation_tier' => $article->automation_tier,
            ])->values()->all(),
            'supported_intents' => $this->planGuard->supportedIntents(),
            'allowed_actions' => $workflow->allowed_actions ?? [],
            'fact_contract_version' => ProductQuestionFactContract::VERSION,
            'fact_contract' => $this->contract->definitions(),
            'reply_style' => [
                'respond_to_customer_concern' => true,
                'prefer_conversational_guidance' => true,
                'concise' => true,
                'warm_customer_service_voice' => true,
                'natural_and_polished' => true,
                'soften_limitations_without_weakening_them' => true,
                'simple_question_target_words' => 60,
                'maximum_words' => 180,
                'do_not_repeat_product_title' => true,
                'do_not_add_generic_contact_referral' => true,
                'do_not_invent_missing_facts' => true,
                'avoid_empty_greetings_and_repeated_thanks' => true,
                'avoid_excessive_enthusiasm_and_exclamation_marks' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function resolveFacts(ProductQuestionDraftRequest $request, array $plan): array
    {
        $plan = $this->validatedPlan($request->question, $plan);
        $resolved = $this->facts->resolve($request->question, $plan);
        $workflow = $this->workflows->runnable($request->workflow_key, $request->workflow_version);
        $currentInput = $this->input->capture(
            $request->question->fresh(),
            $request->workflow_key,
            $request->workflow_version,
            $workflow->prompt_version ?: self::PROMPT_VERSION,
        );
        $request->update(['input_hash' => $currentInput['hash'], 'input_snapshot' => $currentInput['snapshot']]);

        return ['plan' => $plan, 'input_hash' => $currentInput['hash'], ...$resolved];
    }

    /** @return array{data:list<array<string,mixed>>} */
    public function policies(ProductQuestionDraftRequest $request, array $intents): array
    {
        $question = $request->question()->with('site')->firstOrFail();
        $allowed = $this->availablePolicies($question);
        $selected = $allowed->filter(fn (KnowledgeArticle $article): bool => in_array(
            $this->intent($article),
            $intents,
            true,
        ));

        return ['data' => $selected->map(fn (KnowledgeArticle $article): array => [
            'id' => $article->id,
            'version' => $article->version,
            'intent' => $this->intent($article),
            'title' => $article->title,
            'applicability' => $article->applicability,
            'automation_tier' => $article->automation_tier,
            'required_facts' => $article->required_facts,
            'policy_rules' => $article->policy_rules,
            'decision_tree' => $article->decision_tree,
            'reply_strategy' => $article->reply_strategy,
            'reply_templates' => $article->reply_templates,
            'prohibited_claims' => $article->prohibited_claims,
            'escalation_rules' => $article->escalation_rules,
        ])->values()->all()];
    }

    /** @return array<string, mixed> */
    public function complete(ProductQuestionDraftRequest $request, array $payload): array
    {
        if ($request->status === 'completed' && $request->draft !== null) {
            return ['draft_id' => $request->draft->id, 'status' => $request->draft->status, 'reused' => true];
        }
        if ($request->status !== 'running') {
            throw new RuntimeException('Draft request is not running.');
        }
        $workflow = $this->workflows->runnable($request->workflow_key, $request->workflow_version);

        $question = $request->question()->with('site')->firstOrFail();
        if (! $question->remote_present || $question->reply_status !== 'unanswered') {
            $this->markStale($request, 'Question is no longer online and unanswered.');
        }
        $currentInput = $this->input->capture(
            $question,
            $request->workflow_key,
            $request->workflow_version,
            $workflow->prompt_version ?: self::PROMPT_VERSION,
        );
        if ($request->input_hash === null) {
            $request->update(['input_hash' => $currentInput['hash'], 'input_snapshot' => $currentInput['snapshot']]);
            $request->refresh();
        }
        if ($request->input_hash !== $currentInput['hash']) {
            $this->markStale($request, 'Draft input changed while the workflow was running.');
        }
        if (isset($payload['input_hash']) && ! hash_equals((string) $request->input_hash, (string) $payload['input_hash'])) {
            $this->markStale($request, 'Workflow completion did not match the leased input hash.');
        }
        $plan = $this->validatedPlan($question, (array) ($payload['plan'] ?? []));
        $facts = (array) ($payload['facts'] ?? []);
        $factSources = array_values((array) ($payload['fact_sources'] ?? []));
        $missing = array_values(array_unique(array_filter((array) ($payload['missing_facts'] ?? []), 'is_string')));
        $review = (array) ($payload['review'] ?? []);
        $decisionPlan = (array) ($review['decision_plan'] ?? []);
        $usesDecisionPlan = ($decisionPlan['version'] ?? null) === 'customer-decision-v1';
        // Legacy workflows compose canned replies here; decision workflows save the reviewed prose.
        if (! $usesDecisionPlan) {
            $review = $this->enforcePetiteSizeWorkflow($question, $plan, $facts, $review);
            $review = $this->enforceCustomSizeWorkflow($question, $plan, $facts, $review);
            $review = $this->enforcePocketPolicy($question, $plan, $facts, $review);
            $review = $this->enforceDressLengthWorkflow($plan, $facts, $review);
            $review = $this->enforceDirectFactResponses($question, $plan, $facts, $review);
        }
        $verifiedPolicyIntents = $this->availablePolicies($question)
            ->map(fn (KnowledgeArticle $article): string => $this->intent($article))
            ->values()
            ->all();
        if (! $usesDecisionPlan) {
            $review = $this->enforcePostProductionCancellationPolicy(
                $question,
                $plan,
                $facts,
                $review,
                $verifiedPolicyIntents,
            );
            if (version_compare($request->workflow_version, '1.2.0', '>=')) {
                $review = $this->enforceConservativeBlockingResponse($plan, $facts, $missing, $review, $verifiedPolicyIntents);
            }
        }
        if ($usesDecisionPlan) {
            $answerEn = trim((string) ($review['final_answer_en'] ?? ''));
            $answerLocalized = trim((string) ($review['final_answer_localized'] ?? ''));
        } else {
            $answerEn = $this->sanitizeUnsupportedFollowUpActions(
                $this->sanitizeProductIdentity(trim((string) ($review['final_answer_en'] ?? '')), $facts, $question),
                $workflow->allowed_actions ?? [],
            );
            $answerLocalized = $this->sanitizeUnsupportedFollowUpActions(
                $this->sanitizeProductIdentity(trim((string) ($review['final_answer_localized'] ?? '')), $facts, $question),
                $workflow->allowed_actions ?? [],
            );
        }
        $safetyFlags = array_values(array_unique(array_filter([
            ...((array) ($payload['safety_flags'] ?? [])),
            ...((array) ($review['safety_flags'] ?? [])),
        ], 'is_string')));
        $recommendedVisible = (bool) ($payload['recommended_visible'] ?? false);
        $strategy = (array) ($decisionPlan['service_strategy'] ?? []);
        $emailHandoff = $usesDecisionPlan
            && ($strategy['response_mode'] ?? null) === 'email_handoff'
            && ($strategy['public_value'] ?? null) === 'individual_case'
            && ($strategy['can_resolve_in_one_reply'] ?? null) === false
            && ($strategy['recommended_visible'] ?? null) === false
            && is_array($strategy['email_context'] ?? null)
            && ! empty($strategy['email_context'])
            && filter_var($question->site->customer_service_email, FILTER_VALIDATE_EMAIL)
            && ($strategy['contact_email'] ?? null) === $question->site->customer_service_email
            && data_get($facts, 'site.customer_service_email') === $question->site->customer_service_email;
        if ($usesDecisionPlan && $strategy !== []) {
            $recommendedVisible = $recommendedVisible
                && ($strategy['recommended_visible'] ?? false) === true
                && ($strategy['public_value'] ?? null) === 'general_product'
                && ($strategy['response_mode'] ?? null) === 'answer';
        }
        $status = ($review['approved'] ?? false) === true && ($review['must_block'] ?? true) === false
            ? 'generated'
            : 'blocked';

        if ($usesDecisionPlan) {
            if (($strategy['response_mode'] ?? null) === 'human_review'
                || (($strategy['response_mode'] ?? null) === 'email_handoff'
                    && (! $emailHandoff || ! str_contains($answerEn, (string) $strategy['contact_email'])))) {
                $status = 'blocked';
                $safetyFlags[] = 'Service strategy needs human review or lacks a verified consultation channel.';
            }
            if (empty($decisionPlan['customer_goal']) || empty($decisionPlan['answer_units'])) {
                $status = 'blocked';
                $safetyFlags[] = 'Decision reply is missing its customer decision plan.';
            }
            $qualityChecks = ['factual_accuracy', 'relevance', 'actionable_guidance', 'natural_tone', 'decision_risks', 'intent_coverage', 'language_equivalence'];
            foreach ($qualityChecks as $check) {
                if (data_get($review, 'quality_checks.'.$check) !== true) {
                    $status = 'blocked';
                    $safetyFlags[] = 'Decision reply quality check failed or missing: '.$check;
                }
            }
            foreach ((array) ($decisionPlan['answer_units'] ?? []) as $unit) {
                if (! empty($unit['essential_unknowns'])) {
                    $status = 'blocked';
                    $safetyFlags[] = 'Essential business evidence remains unverified.';
                }
            }
        }

        if ($answerEn === '') {
            $status = 'blocked';
            $safetyFlags[] = 'Review did not return a usable English answer.';
        }
        if ($this->hasBlockingMissingFacts($missing)) {
            $status = 'blocked';
            $safetyFlags[] = 'Required current facts are missing; Preview is not allowed.';
        }
        if (preg_match('/\{\{[^}]+\}\}/', $answerEn.' '.$answerLocalized) === 1) {
            $status = 'blocked';
            $safetyFlags[] = 'The answer contains an unresolved template variable.';
        }
        if ($recommendedVisible && $this->containsPrivateIdentifier($answerEn.' '.$answerLocalized, $question)) {
            $status = 'blocked';
            $safetyFlags[] = 'A public answer contains a private customer or order identifier.';
        }
        if (! $emailHandoff && $this->containsUnsupportedGenericContactReferral($answerEn.' '.$answerLocalized, (array) ($payload['policies'] ?? []))) {
            $status = 'blocked';
            $safetyFlags[] = 'The answer adds a generic contact referral that is not required by the selected policy.';
        }
        if ($this->repeatsProductIdentity($answerEn.' '.$answerLocalized, $facts, $question)) {
            $status = 'blocked';
            $safetyFlags[] = 'The answer unnecessarily repeats the product title or identifier.';
        }
        if ($this->containsUnsupportedFollowUpAction($answerEn.' '.$answerLocalized, $workflow->allowed_actions ?? [])) {
            $status = 'blocked';
            $safetyFlags[] = 'The answer promises a follow-up action that this workflow cannot execute.';
        }
        if ($usesDecisionPlan || version_compare($request->workflow_version, '1.2.0', '>=')) {
            $blockingReasons = $this->planGuard->blockingReasons($plan['intents'], $facts, $missing, $verifiedPolicyIntents);
            // A broad individual consultation can be routed without asserting alteration feasibility.
            // Specific unsupported alterations and other independent requests retain their guards.
            if ($emailHandoff) {
                $blockingReasons = array_values(array_diff($blockingReasons, ['intent_requires_human_review:request_other_customization']));
            }
            if ($blockingReasons !== []) {
                $status = 'blocked';
                $safetyFlags = [...$safetyFlags, ...$blockingReasons];
            }
            $coveredIntents = array_values(array_filter((array) ($review['covered_intents'] ?? []), 'is_string'));
            if (array_diff($plan['intents'], $coveredIntents) !== []) {
                $status = 'blocked';
                $safetyFlags[] = 'The final answer does not cover every planned intent.';
            }
        }

        $knowledgeArticleIds = collect((array) ($payload['policies'] ?? []))
            ->pluck('id')->filter(fn (mixed $id): bool => is_numeric($id))->map(fn ($id): int => (int) $id)->values()->all();
        $formatter = app(ProductQuestionReplyFormatter::class);
        $answerEn = $formatter->format($answerEn);
        $answerLocalized = $formatter->format($answerLocalized);
        $draft = DB::transaction(function () use (
            $request,
            $question,
            $payload,
            $plan,
            $facts,
            $factSources,
            $missing,
            $review,
            $answerEn,
            $answerLocalized,
            $recommendedVisible,
            $safetyFlags,
            $knowledgeArticleIds,
            $status,
            $workflow,
        ): ProductQuestionDraft {
            $draft = ProductQuestionDraft::create([
                'product_question_id' => $question->id,
                'status' => $status,
                'provider' => app(DraftCompletionClient::class)->provider(),
                'model' => app(DraftCompletionClient::class)->model(),
                'prompt_version' => $workflow->prompt_version ?: self::PROMPT_VERSION,
                'intents' => $plan['intents'],
                'knowledge_article_ids' => $knowledgeArticleIds,
                'facts' => $facts,
                'fact_sources' => $factSources,
                'missing_facts' => $missing,
                'safety_flags' => array_values(array_unique($safetyFlags)),
                'customer_language' => $plan['customer_language'],
                'answer_en' => $answerEn ?: null,
                'answer_localized' => $plan['customer_language'] === 'en' || $answerLocalized === '' ? null : $answerLocalized,
                'recommended_visible' => $recommendedVisible,
                'requires_human_review' => true,
                'confidence' => is_numeric($review['confidence'] ?? null) ? (float) $review['confidence'] : null,
                'input_hash' => hash('sha256', json_encode([
                    'question' => $question->question,
                    'plan' => $plan,
                    'facts' => $facts,
                    'policies' => $knowledgeArticleIds,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'prompt_hash' => is_string($payload['prompt_hash'] ?? null) ? $payload['prompt_hash'] : null,
                'usage' => (array) ($payload['usage'] ?? []),
                'provider_request_id' => is_string($payload['provider_request_id'] ?? null) ? $payload['provider_request_id'] : null,
                'generated_at' => now(),
            ]);
            $request->update([
                'status' => 'completed',
                'product_question_draft_id' => $draft->id,
                'finished_at' => now(),
                'error_message' => null,
            ]);

            return $draft;
        });

        return ['draft_id' => $draft->id, 'status' => $draft->status, 'reused' => false];
    }

    public function fail(ProductQuestionDraftRequest $request, string $message): void
    {
        if (! in_array($request->status, ['queued', 'running'], true)) {
            return;
        }
        $request->update([
            'status' => 'failed',
            'error_message' => mb_substr(trim($message) ?: 'n8n workflow failed.', 0, 5000),
            'finished_at' => now(),
        ]);
    }

    private function markStale(ProductQuestionDraftRequest $request, string $message): never
    {
        $request->update([
            'status' => 'stale',
            'error_message' => $message,
            'finished_at' => now(),
        ]);

        throw new RuntimeException($message);
    }

    /** @return Collection<int, KnowledgeArticle> */
    private function availablePolicies(ProductQuestion $question): Collection
    {
        return $this->knowledge->available('product_question', $question->site->site_key);
    }

    private function intent(KnowledgeArticle $article): string
    {
        return $this->knowledge->intentKey($article);
    }

    /** @return array<string, mixed> */
    private function validatedPlan(ProductQuestion $question, array $plan): array
    {
        $question->loadMissing('site');
        $allowed = $this->planGuard->supportedIntents();
        $rawIntents = (array) ($plan['intents'] ?? []);
        if (! array_is_list($rawIntents)) {
            $rawIntents = array_keys($rawIntents);
        }
        $intents = array_values(array_unique(array_filter(
            $rawIntents,
            fn (mixed $intent): bool => is_string($intent) && in_array($intent, $allowed, true),
        )));
        $questionText = trim($question->question);
        if ($this->asksHowToEnterMeasurements($questionText) && ! $this->asksForSizeAdvice($questionText)) {
            $intents = array_values(array_diff($intents, ['ask_size_recommendation']));
            if (in_array('request_custom_size', $allowed, true)) {
                $intents[] = 'request_custom_size';
            }
        }
        if ($this->asksForPetiteSize($questionText)) {
            $intents = array_values(array_diff($intents, ['ask_in_stock', 'ask_plus_size']));
            foreach (['ask_size_recommendation', 'request_custom_size'] as $intent) {
                if (in_array($intent, $allowed, true)) {
                    $intents[] = $intent;
                }
            }
        }
        if ($this->asksForCustomColor($questionText)) {
            $intents = array_values(array_diff($intents, ['request_custom_length']));
            if (in_array('request_custom_color', $allowed, true)) {
                $intents[] = 'request_custom_color';
            }
        }
        if ($this->asksWhetherProductHasPockets($questionText)
            && in_array('ask_pockets', $allowed, true)
            && in_array('request_add_pockets', $allowed, true)
            && ! in_array('request_add_pockets', $intents, true)
        ) {
            $intents[] = 'request_add_pockets';
        }
        $intents = $this->planGuard->normalizeIntents($questionText, array_values(array_unique($intents)));
        $unmatchedRequests = array_values(array_filter((array) ($plan['unmatched_requests'] ?? []), 'is_string'));
        if (in_array('ask_how_to_order', $intents, true)
            && preg_match('/\bproduct page\b.{0,60}\b(?:open|load)\b/i', $questionText) === 1
        ) {
            $unmatchedRequests = array_values(array_filter(
                $unmatchedRequests,
                fn (string $request): bool => preg_match('/\b(?:product page|open|load|technical_issue|browsing)\b/i', $request) !== 1,
            ));
        }
        if ($this->asksForPetiteSize($questionText)) {
            $unmatchedRequests = array_values(array_filter(
                $unmatchedRequests,
                fn (string $request): bool => preg_match('/\bpetite\b/i', $request) !== 1,
            ));
        }
        if (count($intents) > 6 || ($intents === [] && $unmatchedRequests === [])) {
            throw new RuntimeException('Planner must select up to six supported intents or identify an unmatched request.');
        }
        $language = strtolower((string) ($plan['customer_language'] ?? 'en'));
        if (preg_match('/^[a-z]{2}$/', $language) !== 1) {
            $language = 'en';
        }
        $country = $plan['country_code'] ?? null;
        if (! is_string($country) || preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
            $country = null;
        }

        return [
            'intents' => $intents,
            'customer_language' => $language,
            'country_code' => $country === null ? null : strtoupper($country),
            'event_date' => $this->eventDate($questionText, $plan['event_date'] ?? null),
            'requested_currency' => is_string($plan['requested_currency'] ?? null) ? strtoupper($plan['requested_currency']) : null,
            'requested_language' => is_string($plan['requested_language'] ?? null) ? strtolower($plan['requested_language']) : null,
            'requires_private_reply' => (bool) ($plan['requires_private_reply'] ?? false),
            'unmatched_requests' => $unmatchedRequests,
            'clauses' => $this->planGuard->normalizeClauses(
                $questionText,
                array_values(array_filter((array) ($plan['clauses'] ?? []), 'is_array')),
                $intents,
            ),
            'summary' => is_string($plan['summary'] ?? null) ? $plan['summary'] : '',
        ];
    }

    private function hasBlockingMissingFacts(array $missing): bool
    {
        return collect($missing)->contains(fn (string $fact): bool => $fact === 'private_order_lookup'
            || $fact === 'current_product_snapshot'
            || str_ends_with($fact, '_api_result')
            || str_starts_with($fact, 'unsupported_request:'));
    }

    private function redact(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[EMAIL]', $text) ?? $text;
        $text = preg_replace('/\b(?:order\s*(?:number|no\.?|id|#)\s*[:#-]?\s*)?[A-Z]{2,8}\d{6,}(?:-\d+)?\b/i', '[ORDER_ID]', $text) ?? $text;

        return $text;
    }

    private function containsPrivateIdentifier(string $answer, ProductQuestion $question): bool
    {
        if (is_string($question->email) && $question->email !== '' && str_contains(strtolower($answer), strtolower($question->email))) {
            return true;
        }

        return preg_match('/\b(?:order\s*(?:number|no\.?|#)?\s*)?[A-Z]{2,8}\d{6,}(?:-\d+)?\b/i', $answer) === 1;
    }

    /** @param list<array<string, mixed>> $policies */
    private function containsUnsupportedGenericContactReferral(string $answer, array $policies): bool
    {
        $contactPattern = '/\b(?:contact(?:ing)?\s+(?:us|our\s+(?:team|support|customer\s+service))|email\s+us|reach\s+(?:out\s+to\s+)?us)\b|\bsupport@[a-z0-9.-]+\.[a-z]{2,}\b/i';
        if (preg_match($contactPattern, $answer) !== 1) {
            return false;
        }

        $policyText = json_encode($policies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return ! is_string($policyText)
            || (preg_match($contactPattern, $policyText) !== 1
                && ! str_contains($policyText, 'site.customer_service_email'));
    }

    /** @param array<string, mixed> $facts */
    private function repeatsProductIdentity(string $answer, array $facts, ProductQuestion $question): bool
    {
        $productName = data_get($facts, 'product.name');

        return (is_string($productName) && mb_strlen(trim($productName)) >= 5 && mb_stripos($answer, trim($productName)) !== false)
            || ($question->sn !== '' && preg_match('/\b'.preg_quote($question->sn, '/').'\b/i', $answer) === 1);
    }

    /** @param array<string,mixed> $facts */
    private function sanitizeProductIdentity(string $answer, array $facts, ProductQuestion $question): string
    {
        if ($answer === '') {
            return '';
        }
        $productName = data_get($facts, 'product.name');
        $productUrl = data_get($facts, 'product.product_url');
        if (is_string($productName) && mb_strlen(trim($productName)) >= 5) {
            $answer = preg_replace('/'.preg_quote(trim($productName), '/').'/iu', 'this item', $answer) ?? $answer;
        }
        if (is_string($productUrl) && $productUrl !== '') {
            // Preserve the label when removing a product URL from a Markdown link.
            $answer = preg_replace('/\[([^\]]+)\]\(<?'.preg_quote($productUrl, '/').'>?\)/iu', '$1', $answer) ?? $answer;
            $answer = str_ireplace($productUrl, '', $answer);
        }
        if ($question->sn !== '') {
            $quoted = preg_quote($question->sn, '/');
            $answer = preg_replace('/\b(?:product\s+)?(?:SN\s*[:#]?\s*)?'.$quoted.'\b/iu', 'this item', $answer) ?? $answer;
        }
        $answer = preg_replace('/\bthis item\s+(?:product page|page)\b/iu', 'the product page', $answer) ?? $answer;
        $answer = preg_replace('/[ \t]{2,}/', ' ', $answer) ?? $answer;
        $answer = preg_replace('/\s+([,.;:!?])/', '$1', $answer) ?? $answer;

        return trim($answer);
    }

    /** @param list<string> $allowedActions */
    private function containsUnsupportedFollowUpAction(string $answer, array $allowedActions): bool
    {
        if ($allowedActions !== []) {
            return false;
        }

        return preg_match(
            '/\b(?:I|we|(?:our|the|production|support|factory) team|the factory|a human (?:agent|representative))\s*(?:will|\x{2019}ll|\'ll|need to)\s*'
            .'(?:check|verify|review|investigate|look into|follow up|get back|report back|confirm|contact|escalate)\b'
            .'|\b(?:will be|has been)\s+(?:checked|confirmed|reviewed|escalated|noted|forwarded)\b/iu',
            $answer,
        ) === 1;
    }

    /** @param list<string> $allowedActions */
    private function sanitizeUnsupportedFollowUpActions(string $answer, array $allowedActions): string
    {
        if ($answer === '' || $allowedActions !== []) {
            return $answer;
        }
        if (! $this->containsUnsupportedFollowUpAction($answer, [])
            && preg_match('/\b(?:we can|we can separately|let us)\s+(?:check|verify|review|investigate|look into|follow up|double-check|confirm|know)\b/iu', $answer) !== 1
        ) {
            return $answer;
        }
        $sentences = preg_split('/(?<=[.!?])\s+/u', $answer) ?: [$answer];
        $sentences = array_values(array_filter($sentences, function (string $sentence): bool {
            if ($this->containsUnsupportedFollowUpAction($sentence, [])) {
                return false;
            }

            return preg_match('/\b(?:we can|we can separately|let us)\s+(?:check|verify|review|investigate|look into|follow up|double-check|confirm|know)\b/iu', $sentence) !== 1;
        }));

        return trim(implode(' ', $sentences));
    }

    private function eventDate(string $question, mixed $planned): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $planned) === 1) {
            $plannedDate = now()->createFromFormat('Y-m-d', (string) $planned)?->startOfDay();
            if ($plannedDate !== null && $plannedDate->greaterThanOrEqualTo(now()->startOfDay())) {
                return $plannedDate->toDateString();
            }
        }
        if (preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})\b/i', $question, $matches) !== 1) {
            return is_string($planned) ? $planned : null;
        }
        $candidate = now()->createFromFormat('F j Y', ucfirst(strtolower($matches[1])).' '.$matches[2].' '.now()->year)?->startOfDay();
        if ($candidate === null) {
            return is_string($planned) ? $planned : null;
        }
        if ($candidate->isBefore(now()->startOfDay())) {
            $candidate->addYear();
        }

        return $candidate->toDateString();
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $review
     * @param  list<string>  $verifiedPolicyIntents
     * @return array<string, mixed>
     */
    private function enforcePostProductionCancellationPolicy(
        ProductQuestion $question,
        array $plan,
        array $facts,
        array $review,
        array $verifiedPolicyIntents,
    ): array {
        $publicBaseUrl = rtrim((string) data_get($facts, 'site.public_base_url'), '/');
        $customerServiceEmail = (string) data_get($facts, 'site.customer_service_email');
        if ($plan['intents'] !== ['ask_cancellation_policy']
            || ! in_array('ask_cancellation_policy', $verifiedPolicyIntents, true)
            || preg_match('/\b(?:cancel|cancellation)\b.*\b(?:after|once)\b.*\bproduction\b|\bproduction\b.*\b(?:start|begun)\b/iu', $question->question) !== 1
            || filter_var($publicBaseUrl, FILTER_VALIDATE_URL) === false
            || filter_var($customerServiceEmail, FILTER_VALIDATE_EMAIL) === false
        ) {
            return $review;
        }

        $review['approved'] = true;
        $review['must_block'] = false;
        $review['covered_intents'] = ['ask_cancellation_policy'];
        $review['uncovered_intents'] = [];
        $review['final_answer_en'] = sprintf(
            'You may request cancellation after production has started. Any refundable amount is determined under our current Cancellation Policy using the actual production start time confirmed with the factory for your order: %s/article/cancellation-policy.html. Please email %s with your order number and cancellation reason. The exact refund breakdown must be verified before cancellation. We cannot promise cancellation-processing or bank-posting times; bank timing depends on your card issuer.',
            $publicBaseUrl,
            $customerServiceEmail,
        );
        $review['final_answer_localized'] = $plan['customer_language'] === 'en'
            ? $review['final_answer_en']
            : ($review['final_answer_localized'] ?? null);
        $review['safety_flags'] = array_values(array_filter(
            (array) ($review['safety_flags'] ?? []),
            fn (mixed $flag): bool => is_string($flag) && $flag !== 'no_meaningful_localization_applied',
        ));

        return $review;
    }

    /** @param array<string,mixed> $plan
     * @param  array<string,mixed>  $facts
     * @param  array<string,mixed>  $review
     * @return array<string,mixed>
     */
    private function enforceDirectFactResponses(ProductQuestion $question, array $plan, array $facts, array $review): array
    {
        $intents = $plan['intents'];
        $answer = null;
        if ($intents === ['ask_fabric'] && filled(data_get($facts, 'product.attributes.field_fabric.0'))) {
            $answer = 'This dress is made from '.data_get($facts, 'product.attributes.field_fabric.0').'.';
        } elseif ($intents === ['ask_built_in_bra'] && filled(data_get($facts, 'product.attributes.field_builtinbra'))) {
            $answer = strtolower((string) data_get($facts, 'product.attributes.field_builtinbra')) === 'yes'
                ? 'Yes, this dress includes a built-in bra.'
                : 'This dress does not include a built-in bra.';
        } elseif ($intents === ['ask_closure'] && filled(data_get($facts, 'product.attributes.field_backstyle.0'))) {
            $answer = 'The back closes with '.strtolower((string) data_get($facts, 'product.attributes.field_backstyle.0')).'.';
        } elseif ($intents === ['ask_lining'] && filled(data_get($facts, 'product.attributes.field_fullylined'))) {
            $answer = strtolower((string) data_get($facts, 'product.attributes.field_fullylined')) === 'yes'
                ? 'Yes, this dress is fully lined.'
                : 'This dress is not fully lined.';
        } elseif ($intents === ['ask_size_chart']) {
            $answer = 'The Size Guide on the product page contains the current size chart.';
        } elseif ($intents === ['ask_how_to_order'] && preg_match('/\bproduct page\b.{0,60}\b(?:open|load)\b/i', $question->question) === 1) {
            $answer = 'Please refresh the page, then try a private browsing window or a different browser. If it still does not load, include the page URL, error message, and a screenshot with sensitive information hidden when requesting technical support.';
        } elseif ($intents === ['ask_in_stock'] && data_get($facts, 'product.stock_specs.0.status') === 'in_stock') {
            $size = data_get($facts, 'product.stock_specs.0.size');
            $answer = filled($size)
                ? 'The current stock snapshot lists Size '.$size.' as in stock.'
                : 'The current stock snapshot lists this item as in stock.';
        } elseif ($intents === ['ask_plus_size']
            && preg_match('/\b(?:US\s*)?(\d{1,2}W)\b/i', $question->question, $matches) === 1
            && collect((array) data_get($facts, 'product.sizes', []))->contains(fn (mixed $size): bool => strcasecmp((string) $size, $matches[1]) === 0)
        ) {
            $answer = 'Yes, US Size '.strtoupper($matches[1]).' is listed as an available size option.';
        } elseif ($intents === ['request_rush_order'] && data_get($facts, 'product.rush_available') === true) {
            $answer = 'A rush option is available for this item. Select it from the available service options at checkout.';
        } elseif ($intents === ['ask_shipping_options'] && $this->asksAboutImportDuty($question->question)
            && is_bool(data_get($facts, 'shipping.duty_included'))
        ) {
            $answer = data_get($facts, 'shipping.duty_included')
                ? 'Import duty is included in the current shipping charge.'
                : 'Import duty is not included in the shipping charge and may be collected separately by local customs.';
        } elseif ($intents === ['ask_shipping_options'] && filled(data_get($facts, 'shipping.quotes.'.($plan['country_code'] ?? '').'.standard.cost'))) {
            $quote = data_get($facts, 'shipping.quotes.'.$plan['country_code'].'.standard');
            $answer = 'Standard shipping to '.$plan['country_code'].' is '.$quote['cost'].' '.$quote['currency'].'. The final amount is shown at checkout.';
        } elseif ($intents === ['ask_size_recommendation'] && preg_match('/\b(\d+(?:\.\d+)?)\s*(?:inch|inches|in|\x{2033})\s+bust\b/iu', $question->question, $matches) === 1) {
            $answer = $this->sizeRecommendation((float) $matches[1], (array) data_get($facts, 'product.size_chart', []));
        } elseif (in_array('ask_delivery_time', $intents, true)
            && is_string($plan['event_date'] ?? null)
            && is_string(data_get($facts, 'eta.standard.estimated_arrival_min'))
            && is_string(data_get($facts, 'eta.standard.estimated_arrival_max'))
        ) {
            $arrivalMax = now()->createFromFormat('Y-m-d', data_get($facts, 'eta.standard.estimated_arrival_max'))?->startOfDay();
            $eventDate = now()->createFromFormat('Y-m-d', $plan['event_date'])?->startOfDay();
            if ($arrivalMax !== null && $eventDate !== null) {
                $delivery = $arrivalMax->isBefore($eventDate)
                    ? 'The current standard estimate is before your event date, but it is not guaranteed.'
                    : 'The current standard estimate is not before your event date.';
                $answer = sprintf(
                    '%s The estimated arrival range is %s to %s.',
                    $delivery,
                    data_get($facts, 'eta.standard.estimated_arrival_min'),
                    data_get($facts, 'eta.standard.estimated_arrival_max'),
                );
                if (in_array('ask_pockets', $intents, true) && in_array('request_add_pockets', $intents, true)) {
                    $answer = 'This dress does not come with pockets by default, but pockets can be added at no extra charge. Write “Please add pockets” in the Order Notes section at checkout. '.$answer;
                }
            }
        }
        if ($answer === null) {
            return $review;
        }

        $review['approved'] = true;
        $review['must_block'] = false;
        $review['covered_intents'] = $intents;
        $review['uncovered_intents'] = [];
        $review['final_answer_en'] = $answer;
        $review['final_answer_localized'] = $plan['customer_language'] === 'en' ? $answer : null;

        return $review;
    }

    /** @param array<string,mixed> $chart */
    private function sizeRecommendation(float $bust, array $chart): ?string
    {
        $matches = collect($chart)->map(function (mixed $measurements, string|int $size) use ($bust): ?array {
            $sizeBust = data_get($measurements, 'bust');

            return is_numeric($sizeBust) ? ['size' => (string) $size, 'bust' => (float) $sizeBust, 'difference' => abs((float) $sizeBust - $bust)] : null;
        })->filter()->sortBy('difference')->take(2)->values();
        if ($matches->isEmpty()) {
            return null;
        }
        $first = $matches[0];
        $second = $matches->get(1);
        if ($second !== null && abs($first['difference'] - $second['difference']) < 0.001) {
            return sprintf(
                'A %.1f-inch bust falls between US Sizes %s (%.1f in) and %s (%.1f in). Compare your waist and hip measurements with the Size Guide before choosing.',
                $bust,
                $first['size'],
                $first['bust'],
                $second['size'],
                $second['bust'],
            );
        }

        return sprintf(
            'For a %.1f-inch bust, US Size %s is the closest chart match at %.1f inches. Compare your waist and hip measurements before choosing.',
            $bust,
            $first['size'],
            $first['bust'],
        );
    }

    private function asksAboutImportDuty(string $question): bool
    {
        return preg_match('/\b(?:import )?(?:duty|duties|customs?)\b/i', $question) === 1;
    }

    /** @param array<string,mixed> $plan
     * @param  array<string,mixed>  $facts
     * @param  list<string>  $missing
     * @param  array<string,mixed>  $review
     * @return array<string,mixed>
     */
    private function enforceConservativeBlockingResponse(
        array $plan,
        array $facts,
        array $missing,
        array $review,
        array $verifiedPolicyIntents,
    ): array {
        $reasons = $this->planGuard->blockingReasons($plan['intents'], $facts, $missing, $verifiedPolicyIntents);
        if ($reasons === []) {
            return $review;
        }

        $responses = [];
        foreach ($plan['intents'] as $intent) {
            $response = match ($intent) {
                'ask_product_photos' => 'The available images are catalog images; they do not confirm that additional actual-product photos are available.',
                'ask_color_photos' => 'The available images are catalog images; they do not confirm that additional real-color photos are available.',
                'ask_fit' => 'The available product facts do not confirm whether this style runs small or large. Please compare your measurements with the size chart.',
                'ask_swatch' => 'Fabric swatch availability is not covered by a verified policy yet, so it cannot be confirmed in this draft.',
                'request_custom_color' => 'The requested custom color cannot be confirmed as a manufacturing option from the available facts.',
                'request_custom_length' => 'The requested length change cannot be confirmed from the available product facts.',
                'request_remove_train' => 'The requested train alteration cannot be confirmed from the available product facts.',
                'request_add_sleeves', 'request_remove_sleeves' => 'The requested sleeve alteration cannot be confirmed from the available product facts.',
                'request_change_neckline', 'request_change_back' => 'The requested structural alteration cannot be confirmed from the available product facts.',
                'request_add_train', 'request_mix_designs', 'request_other_customization' => 'This design customization cannot be confirmed from the available product facts.',
                'ask_restock' => 'No verified restock date is available in the current product facts.',
                'ask_discount' => 'No verified policy authorizes an extra discount, so one cannot be promised in this draft.',
                'ask_guarantee' => 'A guaranteed delivery date cannot be promised; checkout dates are estimates.',
                'ask_cancellation_policy' => 'Cancellation after production starts is not covered by a verified policy yet, so the applicable terms cannot be confirmed in this draft.',
                'ask_tracking' => 'Order-specific tracking details cannot be verified in public Q&A; this draft must remain blocked for private human review.',
                'ask_refund' => 'Order-specific refund details cannot be verified in public Q&A; this draft must remain blocked for private human review.',
                'ask_order_status' => 'Order-specific status details cannot be verified in public Q&A; this draft must remain blocked for private human review.',
                'spam_or_irrelevant' => 'This message is unrelated to product support and should not receive a public reply.',
                default => null,
            };
            if ($response !== null) {
                $responses[] = $response;
            }
        }
        if (in_array('missing_required_live_fact', $reasons, true)) {
            $responses[] = 'The required destination or current live fact is missing, so this request cannot be confirmed yet.';
        }
        if ($responses === []) {
            return $review;
        }

        $review['approved'] = false;
        $review['must_block'] = true;
        $review['covered_intents'] = $plan['intents'];
        $review['uncovered_intents'] = [];
        $review['final_answer_en'] = implode(' ', array_values(array_unique($responses)));
        $review['final_answer_localized'] = null;
        $review['safety_flags'] = array_values(array_unique([
            ...array_filter((array) ($review['safety_flags'] ?? []), 'is_string'),
            ...$reasons,
        ]));

        return $review;
    }

    private function asksWhetherProductHasPockets(string $question): bool
    {
        return preg_match('/\bpockets?\b/i', $question) === 1
            && preg_match('/\b(?:does|do|has|have|with|include|come)\b.{0,35}\bpockets?\b|\bpockets?\b.{0,35}\b(?:included|available)\b/i', $question) === 1;
    }

    private function asksHowToEnterMeasurements(string $question): bool
    {
        return preg_match('/\bmeasurements?\b/i', $question) === 1
            && preg_match('/\b(?:where|how|provide|enter|add|put|submit|see)\b/i', $question) === 1;
    }

    private function asksForSizeAdvice(string $question): bool
    {
        return preg_match('/\b(?:(?:what|which)\s+size|size\s+(?:should|would)|recommend\w*\s+(?:a\s+)?size|fit\s+me|choose\s+(?:a\s+)?size)\b/i', $question) === 1;
    }

    private function asksForPetiteSize(string $question): bool
    {
        return preg_match('/\bpetite\b/i', $question) === 1
            && preg_match('/\bsize\s*[0-9]{1,2}[a-z]?\b|\bpetite\s+size\b/i', $question) === 1;
    }

    private function requestedStandardSize(string $question): ?string
    {
        if (preg_match('/\bsize\s*([0-9]{1,2}[a-z]?)\b/i', $question, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    private function asksForCustomColor(string $question): bool
    {
        $mentionsColor = preg_match('/\bcolou?rs?\b|\bfarben?\b|\bcouleurs?\b|\bcolores?\b/iu', $question) === 1;
        $requestsCustomization = preg_match(
            '/\bcustom(?:i[sz](?:e|ed|ation))?\b|\bmade\s+(?:in|with)\b|\b(?:available|offered)\s+in\b|'
            .'\b(?:different|another|other)\s+colou?r\b|\banfertigen\b|\bsonderanfertigung\b|\bwunschfarbe\b|'
            .'\bpersonnalis(?:er|é|ée)\b|\bsur\s+mesure\b|\bpersonaliz(?:ar|ado|ada)\b/iu',
            $question,
        ) === 1;

        return $mentionsColor && $requestsCustomization;
    }

    /** @return array<string, mixed> */
    private function enforceDressLengthWorkflow(array $plan, array $facts, array $review): array
    {
        if (! in_array('ask_dress_length', $plan['intents'], true)) {
            return $review;
        }
        $length = data_get($facts, 'product.dress_length');
        if (! is_array($length)
            || ! is_numeric($length['value_cm'] ?? null)
            || ! is_numeric($length['value_in'] ?? null)
            || ! is_string($length['size'] ?? null)
            || ! is_string($length['hemline'] ?? null)
        ) {
            return $review;
        }
        $lining = data_get($facts, 'product.attributes.field_fullylined');
        if (in_array('ask_lining', $plan['intents'], true) && ! in_array(strtolower((string) $lining), ['yes', 'no'], true)) {
            return $review;
        }

        $parts = [];
        if (in_array('ask_lining', $plan['intents'], true)) {
            $parts[] = strtolower((string) $lining) === 'yes'
                ? 'Yes, this dress is fully lined.'
                : 'This dress is not fully lined.';
        }
        $measurement = ($length['measurement'] ?? null) === 'hollow_to_hem_front'
            ? 'front hollow-to-hem measurement'
            : 'hollow-to-hem measurement';
        $parts[] = sprintf(
            'For standard US Size %s, the %s %s is approximately %d cm (%.1f in).',
            $length['size'],
            $length['hemline'],
            $measurement,
            $length['value_cm'],
            $length['value_in'],
        );
        $parts[] = 'Just a quick note: this is measured from the hollow at the center base of the neck rather than from the shoulder.';

        $review['approved'] = true;
        $review['must_block'] = false;
        $review['final_answer_en'] = implode(' ', $parts);
        $review['final_answer_localized'] = $plan['customer_language'] === 'en'
            ? $review['final_answer_en']
            : ($review['final_answer_localized'] ?? null);
        $review['safety_flags'] = array_values(array_filter(
            (array) ($review['safety_flags'] ?? []),
            fn (mixed $flag): bool => is_string($flag) && $flag !== 'shoulder_to_hem_not_in_chart',
        ));

        return $review;
    }

    /** @return array<string, mixed> */
    private function enforcePocketPolicy(ProductQuestion $question, array $plan, array $facts, array $review): array
    {
        if (! in_array('ask_pockets', $plan['intents'], true)
            || ! in_array('request_add_pockets', $plan['intents'], true)
            || array_diff($plan['intents'], ['ask_pockets', 'request_add_pockets']) !== []
            || ! $this->asksWhetherProductHasPockets($question->question)
            || data_get($facts, 'product.type') !== 'dress'
        ) {
            return $review;
        }
        $silhouettes = collect((array) data_get($facts, 'product.attributes.field_silhouette', []))
            ->filter(fn (mixed $value): bool => is_string($value))
            ->map(fn (string $value): string => trim($value))
            ->filter();
        if ($silhouettes->isEmpty()) {
            return $review;
        }
        $isMermaid = $silhouettes->contains(fn (string $value): bool => preg_match('/mermaid|trumpet/i', $value) === 1);
        $review['approved'] = true;
        $review['must_block'] = false;
        $review['final_answer_en'] = $isMermaid
            ? 'This dress does not come with pockets by default. Please note that its fitted Mermaid/Trumpet silhouette is not suitable for adding them.'
            : 'This dress does not come with pockets by default, but we’d be happy to add them for you at no extra charge. Simply write “Please add pockets” in the Order Notes section at checkout, and our tailors will take care of it.';
        $review['final_answer_localized'] = $plan['customer_language'] === 'en'
            ? $review['final_answer_en']
            : ($review['final_answer_localized'] ?? null);
        $review['safety_flags'] = array_values(array_filter(
            (array) ($review['safety_flags'] ?? []),
            fn (mixed $flag): bool => is_string($flag) && ! in_array($flag, ['pocket_field_missing', 'intent_scope_overreach_in_draft'], true),
        ));

        return $review;
    }

    /** @return array<string, mixed> */
    private function enforcePetiteSizeWorkflow(ProductQuestion $question, array $plan, array $facts, array $review): array
    {
        $size = $this->requestedStandardSize($question->question);
        $availableSizes = collect((array) data_get($facts, 'product.sizes', []))
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => strtoupper(trim((string) $value)));
        if (! $this->asksForPetiteSize($question->question)
            || ! in_array('ask_size_recommendation', $plan['intents'], true)
            || ! in_array('request_custom_size', $plan['intents'], true)
            || data_get($facts, 'product.type') !== 'dress'
            || data_get($facts, 'product.custom_size_input_available') !== true
            || $size === null
            || ! $availableSizes->contains($size)
        ) {
            return $review;
        }

        $review['approved'] = true;
        $review['must_block'] = false;
        $review['final_answer_en'] = "While we don’t offer a separate petite Size {$size}, this dress is available in standard US Size {$size}. We recommend comparing your bust, waist, hips, and hollow-to-floor measurements with our Size Chart. If you’d prefer a closer fit for petite proportions, you’re welcome to choose Custom Size and enter your exact measurements. Please note that custom-size dresses cannot be returned or exchanged.";
        $review['final_answer_localized'] = $plan['customer_language'] === 'en'
            ? $review['final_answer_en']
            : ($review['final_answer_localized'] ?? null);
        $review['safety_flags'] = array_values(array_filter(
            (array) ($review['safety_flags'] ?? []),
            fn (mixed $flag): bool => is_string($flag),
        ));

        return $review;
    }

    /** @return array<string, mixed> */
    private function enforceCustomSizeWorkflow(ProductQuestion $question, array $plan, array $facts, array $review): array
    {
        if (! in_array('request_custom_size', $plan['intents'], true)
            || data_get($facts, 'product.custom_size_input_available') !== true
            || data_get($facts, 'product.custom_size_entry_method') !== 'size_selector'
            || preg_match('/\bmeasurements?\b/i', $question->question) !== 1
            || preg_match('/\b(?:where|how|provide|enter|add|put|submit|see)\b/i', $question->question) !== 1
        ) {
            return $review;
        }
        $review['approved'] = true;
        $review['must_block'] = false;
        $review['final_answer_en'] = 'You can enter your measurements by opening the Size options and selecting Custom Size. Complete the measurement fields that appear, then continue with your order. For your privacy, please don’t post your measurements in this public Q&A.';
        $review['final_answer_localized'] = $plan['customer_language'] === 'en'
            ? $review['final_answer_en']
            : ($review['final_answer_localized'] ?? null);
        $review['safety_flags'] = array_values(array_filter(
            (array) ($review['safety_flags'] ?? []),
            fn (mixed $flag): bool => is_string($flag),
        ));

        return $review;
    }
}
