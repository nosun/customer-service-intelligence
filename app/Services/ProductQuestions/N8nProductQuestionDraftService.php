<?php

namespace App\Services\ProductQuestions;

use App\Models\KnowledgeArticle;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionDraft;
use App\Models\ProductQuestionDraftRequest;
use App\Services\N8n\WorkflowRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class N8nProductQuestionDraftService
{
    public const WORKFLOW_KEY = 'work/qa-draft-agent-v1';

    public const WORKFLOW_VERSION = '1.1.0';

    public const PROMPT_VERSION = 'n8n-qa-draft-agent-v3';

    public function __construct(
        private readonly ProductQuestionFactResolver $facts,
        private readonly ProductQuestionFactContract $contract,
        private readonly ProductQuestionDraftInput $input,
        private readonly WorkflowRegistry $workflows,
    ) {}

    /** @return array<string, mixed> */
    public function start(ProductQuestionDraftRequest $request, string $executionId): array
    {
        $this->workflows->active(self::WORKFLOW_KEY, self::WORKFLOW_VERSION);
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
        $currentInput = $this->input->capture($request->question);
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
                'intent' => $this->intent($article, $question->site->site_key),
                'title' => $article->title,
                'applicability' => $article->applicability,
                'automation_tier' => $article->automation_tier,
            ])->values()->all(),
            'fact_contract_version' => ProductQuestionFactContract::VERSION,
            'fact_contract' => $this->contract->definitions(),
            'reply_style' => [
                'lead_with_direct_answer' => true,
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
        $currentInput = $this->input->capture($request->question->fresh());
        $request->update(['input_hash' => $currentInput['hash'], 'input_snapshot' => $currentInput['snapshot']]);

        return ['plan' => $plan, 'input_hash' => $currentInput['hash'], ...$resolved];
    }

    /** @return array{data:list<array<string,mixed>>} */
    public function policies(ProductQuestionDraftRequest $request, array $intents): array
    {
        $question = $request->question()->with('site')->firstOrFail();
        $allowed = $this->availablePolicies($question);
        $selected = $allowed->filter(fn (KnowledgeArticle $article): bool => in_array(
            $this->intent($article, $question->site->site_key),
            $intents,
            true,
        ));

        return ['data' => $selected->map(fn (KnowledgeArticle $article): array => [
            'id' => $article->id,
            'version' => $article->version,
            'intent' => $this->intent($article, $question->site->site_key),
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

        $question = $request->question()->with('site')->firstOrFail();
        if (! $question->remote_present || $question->reply_status !== 'unanswered') {
            $this->markStale($request, 'Question is no longer online and unanswered.');
        }
        $currentInput = $this->input->capture($question);
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
        $review = $this->enforcePetiteSizeWorkflow($question, $plan, $facts, $review);
        $review = $this->enforceCustomSizeWorkflow($question, $plan, $facts, $review);
        $review = $this->enforcePocketPolicy($question, $plan, $facts, $review);
        $review = $this->enforceDressLengthWorkflow($plan, $facts, $review);
        $answerEn = trim((string) ($review['final_answer_en'] ?? ''));
        $answerLocalized = trim((string) ($review['final_answer_localized'] ?? ''));
        $safetyFlags = array_values(array_unique(array_filter([
            ...((array) ($payload['safety_flags'] ?? [])),
            ...((array) ($review['safety_flags'] ?? [])),
        ], 'is_string')));
        $recommendedVisible = (bool) ($payload['recommended_visible'] ?? false);
        $status = ($review['approved'] ?? false) === true && ($review['must_block'] ?? true) === false
            ? 'generated'
            : 'blocked';

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
        if ($this->containsUnsupportedGenericContactReferral($answerEn.' '.$answerLocalized, (array) ($payload['policies'] ?? []))) {
            $status = 'blocked';
            $safetyFlags[] = 'The answer adds a generic contact referral that is not required by the selected policy.';
        }
        if ($this->repeatsProductIdentity($answerEn.' '.$answerLocalized, $facts, $question)) {
            $status = 'blocked';
            $safetyFlags[] = 'The answer unnecessarily repeats the product title or identifier.';
        }

        $knowledgeArticleIds = collect((array) ($payload['policies'] ?? []))
            ->pluck('id')->filter(fn (mixed $id): bool => is_numeric($id))->map(fn ($id): int => (int) $id)->values()->all();
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
        ): ProductQuestionDraft {
            $draft = ProductQuestionDraft::create([
                'product_question_id' => $question->id,
                'status' => $status,
                'provider' => 'minimax',
                'model' => (string) ($payload['model'] ?? config('services.minimax.model')),
                'prompt_version' => self::PROMPT_VERSION,
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
        $prefixes = ['qa_global_', 'qa_'.$question->site->site_key.'_'];

        return KnowledgeArticle::query()
            ->where('status', 'verified')
            ->where(function ($query) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $query->orWhere('key', 'like', $prefix.'%');
                }
            })
            ->orderByRaw('site_key is null')
            ->get()
            ->unique(fn (KnowledgeArticle $article): string => $this->intent($article, $question->site->site_key))
            ->values();
    }

    private function intent(KnowledgeArticle $article, string $siteKey): string
    {
        foreach (['qa_'.$siteKey.'_', 'qa_global_'] as $prefix) {
            if (str_starts_with($article->key, $prefix)) {
                return substr($article->key, strlen($prefix));
            }
        }

        return $article->key;
    }

    /** @return array<string, mixed> */
    private function validatedPlan(ProductQuestion $question, array $plan): array
    {
        $question->loadMissing('site');
        $allowed = $this->availablePolicies($question)
            ->map(fn (KnowledgeArticle $article): string => $this->intent($article, $question->site->site_key))
            ->all();
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
            $intents = array_values(array_diff($intents, ['ask_plus_size']));
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
        $intents = array_values(array_unique($intents));
        $unmatchedRequests = array_values(array_filter((array) ($plan['unmatched_requests'] ?? []), 'is_string'));
        if (count($intents) > 4 || ($intents === [] && $unmatchedRequests === [])) {
            throw new RuntimeException('Planner must select up to four verified policy intents or identify an unmatched request.');
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
            'event_date' => is_string($plan['event_date'] ?? null) ? $plan['event_date'] : null,
            'requested_currency' => is_string($plan['requested_currency'] ?? null) ? strtoupper($plan['requested_currency']) : null,
            'requested_language' => is_string($plan['requested_language'] ?? null) ? strtolower($plan['requested_language']) : null,
            'requires_private_reply' => (bool) ($plan['requires_private_reply'] ?? false),
            'unmatched_requests' => $unmatchedRequests,
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

        return ! is_string($policyText) || preg_match($contactPattern, $policyText) !== 1;
    }

    /** @param array<string, mixed> $facts */
    private function repeatsProductIdentity(string $answer, array $facts, ProductQuestion $question): bool
    {
        $productName = data_get($facts, 'product.name');

        return (is_string($productName) && mb_strlen(trim($productName)) >= 5 && mb_stripos($answer, trim($productName)) !== false)
            || ($question->sn !== '' && preg_match('/\b'.preg_quote($question->sn, '/').'\b/i', $answer) === 1);
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
