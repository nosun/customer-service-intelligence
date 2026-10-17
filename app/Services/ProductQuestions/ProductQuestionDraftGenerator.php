<?php

namespace App\Services\ProductQuestions;

use App\Models\KnowledgeArticle;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionDraft;
use App\Services\AI\MiniMaxClient;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class ProductQuestionDraftGenerator
{
    public function __construct(
        private readonly MiniMaxClient $client,
        private readonly ProductQuestionDraftPrompt $prompt,
        private readonly ProductQuestionFactResolver $facts,
        private readonly ProductQuestionFactContract $contract,
    ) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function generate(ProductQuestion $question): ProductQuestionDraft
    {
        $question->loadMissing(['site', 'analysis']);
        $available = $this->availablePolicies($question);
        $questionText = $this->redact(trim($question->question));
        $input = [
            'question_id' => $question->id,
            'site_key' => $question->site->site_key,
            'product_sn' => $question->sn,
            'question' => $questionText,
            'question_created_at' => $question->remote_created_at?->toIso8601String(),
            'current_date' => now()->toDateString(),
            'existing_analysis' => $question->analysis ? [
                'intent_key' => $question->analysis->intent_key,
                'customer_intent' => $question->analysis->customer_intent,
                'secondary_categories' => $question->analysis->secondary_categories,
                'risk_level' => $question->analysis->risk_level,
            ] : null,
            'available_policies' => $available->map(fn (KnowledgeArticle $article): array => [
                'intent' => $this->intent($article, $question->site->site_key),
                'title' => $article->title,
                'applicability' => $article->applicability,
                'automation_tier' => $article->automation_tier,
            ])->values()->all(),
        ];
        $inputHash = hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            $planCompletion = $this->structuredCompletion(
                $this->prompt->planSystem(),
                $this->prompt->user($input),
            );
            $plan = $this->validatePlan($planCompletion['output'], $available, $question->site->site_key, $question);
            $articles = $this->selectPolicies($available, $plan['intents'], $question->site->site_key);
            if ($articles->isEmpty()) {
                return $this->blocked($question, $inputHash, $plan, ['没有可用于该问题的 verified 策略。'], $planCompletion);
            }

            $resolved = $this->facts->resolve($question, $plan);
            if ($this->shouldAddPocketCustomizationFallback($plan, $resolved['facts'], $available, $question->site->site_key)) {
                $plan['intents'][] = 'request_add_pockets';
                $plan['intents'] = array_values(array_unique($plan['intents']));
                $articles = $this->selectPolicies($available, $plan['intents'], $question->site->site_key);
            }
            $draftPayload = [
                'question' => $questionText,
                'product_sn' => $question->sn,
                'customer_language' => $plan['customer_language'],
                'selected_intents' => $plan['intents'],
                'unmatched_requests' => $plan['unmatched_requests'],
                'customer_request_summary' => $plan['summary'],
                'policies' => $articles->map(fn (KnowledgeArticle $article): array => [
                    'id' => $article->id,
                    'intent' => $this->intent($article, $question->site->site_key),
                    'title' => $article->title,
                    'automation_tier' => $article->automation_tier,
                    'applicability' => $article->applicability,
                    'required_facts' => $article->required_facts,
                    'policy_rules' => $article->policy_rules,
                    'decision_tree' => $article->decision_tree,
                    'reply_strategy' => $article->reply_strategy,
                    'reply_templates' => $article->reply_templates,
                    'prohibited_claims' => $article->prohibited_claims,
                    'escalation_rules' => $article->escalation_rules,
                ])->values()->all(),
                'fact_contract_version' => ProductQuestionFactContract::VERSION,
                'fact_contract' => $this->contract->definitions(),
                'current_facts' => $resolved['facts'],
                'missing_facts' => $resolved['missing'],
                'pre_generation_safety_flags' => $resolved['safety_flags'],
                'recommended_visible' => $resolved['recommended_visible'],
            ];
            $systemPrompt = $this->prompt->draftSystem();
            $userPrompt = $this->prompt->user($draftPayload);
            [$completion, $output] = $this->draftCompletion(
                $systemPrompt,
                $userPrompt,
                $plan['customer_language'],
                $plan['intents'],
                in_array('request_custom_size', $plan['intents'], true)
                    && data_get($resolved, 'facts.product.custom_size_input_available') === true
                    && ! $this->reportsCustomSizeFailure($questionText),
                in_array('country_code_for_eta', $resolved['missing'], true),
                (string) data_get($resolved, 'facts.product.name', ''),
                in_array('request_add_pockets', $plan['intents'], true)
                    ? data_get($resolved, 'facts.product.attributes.field_silhouette')
                    : null,
                in_array('ask_pockets', $plan['intents'], true)
                    && ! $this->hasCurrentPocketFact($resolved['facts']),
            );
            $output['unresolved_facts'] = $this->canonicalizeUnresolvedFacts($output['unresolved_facts'], $resolved['missing']);
            $output = $this->applyCustomMeasurementFallback($output, $questionText, $plan, $resolved['facts']);
            $answer = filled($output['answer_localized']) ? $output['answer_localized'] : $output['answer_en'];
            $safetyFlags = array_values(array_unique([
                ...$resolved['safety_flags'],
                ...$output['safety_flags'],
            ]));
            $status = 'generated';
            if (preg_match('/\{\{[^}]+\}\}/', $answer) === 1) {
                $status = 'blocked';
                $safetyFlags[] = '回复中仍有未解析模板变量。';
            }
            if ($resolved['recommended_visible'] && $this->containsPrivateIdentifier($answer, $question)) {
                $status = 'blocked';
                $safetyFlags[] = '公开草稿中检测到客户邮箱或订单标识。';
            }
            $unmatchedRequests = array_map(
                fn (string $request): string => 'unsupported_request: '.$request,
                $plan['unmatched_requests'],
            );
            $missingFacts = array_values(array_unique([
                ...$resolved['missing'],
                ...$output['unresolved_facts'],
                ...$unmatchedRequests,
            ]));
            if ($this->hasBlockingMissingFacts($missingFacts)) {
                $status = 'blocked';
                $safetyFlags[] = '关键事实缺失或实时查询失败，草稿不得直接进入 Preview。';
            }

            return ProductQuestionDraft::create([
                'product_question_id' => $question->id,
                'status' => $status,
                'model' => $this->client->model(),
                'prompt_version' => ProductQuestionDraftPrompt::VERSION,
                'intents' => $plan['intents'],
                'knowledge_article_ids' => $articles->pluck('id')->all(),
                'facts' => $resolved['facts'],
                'fact_sources' => $resolved['sources'],
                'missing_facts' => $missingFacts,
                'safety_flags' => array_values(array_unique($safetyFlags)),
                'customer_language' => $plan['customer_language'],
                'answer_en' => $output['answer_en'],
                'answer_localized' => $output['answer_localized'],
                'recommended_visible' => $resolved['recommended_visible'],
                'requires_human_review' => true,
                'confidence' => $output['confidence'],
                'input_hash' => $inputHash,
                'prompt_hash' => hash('sha256', $systemPrompt."\n".$userPrompt),
                'usage' => $this->usage($planCompletion['usage'], $completion['usage']),
                'provider_request_id' => $completion['request_id'],
                'generated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            return ProductQuestionDraft::create([
                'product_question_id' => $question->id,
                'status' => 'failed',
                'model' => $this->client->model(),
                'prompt_version' => ProductQuestionDraftPrompt::VERSION,
                'intents' => [],
                'knowledge_article_ids' => [],
                'facts' => [],
                'fact_sources' => [],
                'missing_facts' => [],
                'safety_flags' => ['草稿生成失败，禁止进入 Preview。'],
                'recommended_visible' => false,
                'requires_human_review' => true,
                'input_hash' => $inputHash,
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
            ]);
        }
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

    private function validatePlan(array $output, Collection $available, string $siteKey, ProductQuestion $question): array
    {
        $questionText = trim($question->question);
        $allowed = $available->map(fn (KnowledgeArticle $article): string => $this->intent($article, $siteKey))->all();
        $intents = array_values(array_unique(array_filter($output['intents'] ?? [], fn (mixed $intent): bool => is_string($intent) && in_array($intent, $allowed, true))));
        $unmatchedRequests = $this->strings($output['unmatched_requests'] ?? []);
        $eventDate = $this->eventDate($output['event_date'] ?? null, $question);
        if (! $this->asksAboutDelivery($questionText) && $eventDate === null) {
            $intents = array_values(array_diff($intents, ['ask_delivery_time']));
        } elseif ($eventDate !== null && in_array('ask_delivery_time', $allowed, true)) {
            $intents[] = 'ask_delivery_time';
        }
        if ($this->asksHowToEnterMeasurements($questionText) && ! $this->asksForSizeAdvice($questionText)) {
            $intents = array_values(array_diff($intents, ['ask_size_recommendation']));
            if (in_array('request_custom_size', $allowed, true)) {
                $intents = array_values(array_diff($intents, ['ask_how_to_order']));
                $intents[] = 'request_custom_size';
            } else {
                $unmatchedRequests[] = 'how to enter custom measurements for this product';
                if (in_array('ask_how_to_order', $allowed, true)) {
                    $intents[] = 'ask_how_to_order';
                }
            }
        }
        if ($this->asksWhetherProductHasPockets($questionText)) {
            $intents = array_values(array_diff($intents, ['request_add_pockets']));
            if (in_array('ask_pockets', $allowed, true)) {
                $intents[] = 'ask_pockets';
            }
        }
        if ($this->asksToAddPockets($questionText)) {
            $intents = array_values(array_diff($intents, ['ask_pockets']));
            if (in_array('request_add_pockets', $allowed, true)) {
                $intents[] = 'request_add_pockets';
            }
        }
        $intents = array_values(array_unique($intents));
        if (count($intents) > 4) {
            throw new RuntimeException('Draft plan selected more than four intents.');
        }
        $language = strtolower((string) ($output['customer_language'] ?? 'en'));
        if (preg_match('/^[a-z]{2}$/', $language) !== 1) {
            $language = 'en';
        }
        $country = $output['country_code'] ?? null;
        if ($country !== null && (! is_string($country) || preg_match('/^[A-Za-z]{2}$/', $country) !== 1)) {
            $country = null;
        }

        return [
            'intents' => $intents,
            'customer_language' => $language,
            'country_code' => $country === null ? null : strtoupper($country),
            'event_date' => $eventDate,
            'requested_currency' => is_string($output['requested_currency'] ?? null) ? strtoupper($output['requested_currency']) : null,
            'requested_language' => is_string($output['requested_language'] ?? null) ? strtolower($output['requested_language']) : null,
            'requires_private_reply' => (bool) ($output['requires_private_reply'] ?? false),
            'unmatched_requests' => array_values(array_unique($unmatchedRequests)),
            'summary' => is_string($output['summary'] ?? null) ? $output['summary'] : '',
        ];
    }

    private function selectPolicies(Collection $available, array $intents, string $siteKey): Collection
    {
        return $available->filter(fn (KnowledgeArticle $article): bool => in_array($this->intent($article, $siteKey), $intents, true))->values();
    }

    private function eventDate(mixed $value, ProductQuestion $question): ?string
    {
        $questionText = trim($question->question);
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
            $matches = null;
        }
        if (is_array($matches)
            && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
            && preg_match('/\b'.preg_quote($matches[1], '/').'\b/', $questionText) === 1
        ) {
            return $value;
        }

        $months = [
            'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
            'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
            'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        ];
        if (preg_match('/\b('.implode('|', array_keys($months)).')\s+(\d{1,2})(?:st|nd|rd|th)?\b/i', $questionText, $date) !== 1) {
            return null;
        }
        $month = $months[strtolower($date[1])];
        $day = (int) $date[2];
        $reference = $question->remote_created_at ?? $question->created_at ?? now();
        $year = (int) $reference->format('Y');
        if (! checkdate($month, $day, $year)) {
            return null;
        }
        $candidate = $reference->copy()->setDate($year, $month, $day)->startOfDay();
        if ($candidate->lt($reference->copy()->startOfDay())) {
            $candidate->addYear();
        }

        return $candidate->format('Y-m-d');
    }

    private function asksAboutDelivery(string $question): bool
    {
        return preg_match('/\b(?:when|how\s+long|arriv\w*|deliver\w*|shipping\s+time|receive|reach|in\s+time|before)\b/i', $question) === 1;
    }

    private function asksHowToEnterMeasurements(string $question): bool
    {
        return preg_match('/\bmeasurements?\b/i', $question) === 1
            && preg_match('/\b(?:where|how|provide|enter|add|put|submit)\b/i', $question) === 1;
    }

    private function asksForSizeAdvice(string $question): bool
    {
        return preg_match('/\b(?:(?:what|which)\s+size|size\s+(?:should|would)|recommend\w*\s+(?:a\s+)?size|fit\s+me|choose\s+(?:a\s+)?size)\b/i', $question) === 1;
    }

    private function asksWhetherProductHasPockets(string $question): bool
    {
        return preg_match('/\bpockets?\b/i', $question) === 1
            && preg_match('/\b(?:add|added|make|customi[sz]e|include|put)\b.{0,30}\bpockets?\b|\bpockets?\b.{0,30}\b(?:add|added|make|customi[sz]e)\b/i', $question) !== 1;
    }

    private function asksToAddPockets(string $question): bool
    {
        return preg_match('/\bpockets?\b/i', $question) === 1
            && preg_match('/\b(?:add|added|make|customi[sz]e|include|put)\b.{0,30}\bpockets?\b|\bpockets?\b.{0,30}\b(?:add|added|make|customi[sz]e)\b/i', $question) === 1;
    }

    private function shouldAddPocketCustomizationFallback(array $plan, array $facts, Collection $available, string $siteKey): bool
    {
        if (! in_array('ask_pockets', $plan['intents'], true)
            || in_array('request_add_pockets', $plan['intents'], true)
            || $this->hasCurrentPocketFact($facts)
            || ! $available->contains(fn (KnowledgeArticle $article): bool => $this->intent($article, $siteKey) === 'request_add_pockets')
        ) {
            return false;
        }

        $silhouette = data_get($facts, 'product.attributes.field_silhouette', []);

        return collect(is_array($silhouette) ? $silhouette : [$silhouette])
            ->contains(fn (mixed $value): bool => is_string($value) && trim($value) !== '');
    }

    private function hasCurrentPocketFact(array $facts): bool
    {
        $attributes = data_get($facts, 'product.attributes', []);
        if (! is_array($attributes)) {
            return false;
        }

        foreach ($attributes as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, ['pocket', 'pockets', 'field_pocket', 'field_pockets'], true)
                && $value !== null && $value !== '' && $value !== []
            ) {
                return true;
            }
        }

        return false;
    }

    private function applyCustomMeasurementFallback(array $output, string $questionText, array $plan, array $facts): array
    {
        if (! $this->asksHowToEnterMeasurements($questionText)
            || $plan['intents'] !== ['ask_how_to_order']
            || $plan['customer_language'] !== 'en'
            || data_get($facts, 'product.custom_size_input_available') === true
        ) {
            return $output;
        }

        $productName = (string) data_get($facts, 'product.name', 'this item');
        $productSn = (string) data_get($facts, 'product.sn', '');
        $productUrl = (string) data_get($facts, 'product.product_url', data_get($facts, 'site.public_base_url'));
        $serviceEmail = (string) data_get($facts, 'site.customer_service_email', '');
        $sizes = data_get($facts, 'product.sizes', []);
        $sizeRange = is_array($sizes) && count($sizes) > 1
            ? $sizes[0].'–'.$sizes[array_key_last($sizes)]
            : 'the listed standard sizes';
        $contact = $serviceEmail !== ''
            ? "Please contact {$serviceEmail} privately and include item {$productSn}."
            : 'Please contact customer service privately and include the item number.';

        $output['answer_en'] = "Hello, thank you for checking before ordering. The current options for {$productName} confirm standard sizes {$sizeRange}, but they do not confirm a Custom Size measurement form or an Order Notes field. Please do not post your body measurements in this public Q&A.\n\n{$contact} Our team needs to confirm whether this item accepts custom measurements and, if so, provide the correct private submission method before you place the order.\n\nProduct page: {$productUrl}";
        $output['answer_localized'] = null;
        $output['unresolved_facts'] = ['product.custom_size_entry_method'];
        $output['safety_flags'] = array_values(array_unique([
            ...$output['safety_flags'],
            'Custom Size and Order Notes are not confirmed for this item; the safe fallback routes the customer to private support.',
        ]));
        $output['confidence'] = min($output['confidence'], 0.7);

        return $output;
    }

    private function validateDraft(array $output, string $language): array
    {
        $answerEn = trim((string) ($output['answer_en'] ?? ''));
        if ($answerEn === '') {
            throw new RuntimeException('Draft output is missing answer_en.');
        }
        $localized = isset($output['answer_localized']) && is_string($output['answer_localized'])
            ? trim($output['answer_localized']) : null;
        if ($language !== 'en' && ! filled($localized)) {
            throw new RuntimeException('Draft output is missing the localized answer.');
        }

        return [
            'answer_en' => $answerEn,
            'answer_localized' => $language === 'en' ? null : $localized,
            'used_fact_keys' => $this->strings($output['used_fact_keys'] ?? []),
            'unresolved_facts' => $this->strings($output['unresolved_facts'] ?? []),
            'safety_flags' => $this->strings($output['safety_flags'] ?? []),
            'confidence' => max(0, min(1, (float) ($output['confidence'] ?? 0))),
        ];
    }

    /** @param list<string> $intents
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function draftCompletion(
        string $systemPrompt,
        string $userPrompt,
        string $language,
        array $intents,
        bool $forbidCustomSizeReferral,
        bool $forbidEtaFollowup,
        string $productName,
        mixed $addPocketSilhouette,
        bool $usesUnknownPocketFallback,
    ): array {
        $usage = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $completion = $this->structuredCompletion($systemPrompt, $userPrompt);
            $usage = $this->usage($usage, $completion['usage']);
            $output = $this->validateDraft($completion['output'], $language);
            $answer = $output['answer_en'].' '.($output['answer_localized'] ?? '');
            $mentionsUnselectedRush = ! in_array('request_rush_order', $intents, true)
                && preg_match('/\b(?:rush|fast\s+dispatch)\b/i', $answer) === 1;
            $addsUnneededSupportReferral = $forbidCustomSizeReferral
                && preg_match('/\b(?:customer\s+service|support@|contact\s+us|live\s+chat)\b/i', $answer) === 1;
            $asksForDestinationReply = $forbidEtaFollowup
                && preg_match('/\b(?:could\s+you|please)\b.{0,80}\b(?:country|destination)\b|\bwhich\s+country\b/i', $answer) === 1;
            $repeatsProductTitle = mb_strlen($productName) >= 30
                && str_contains(mb_strtolower($answer), mb_strtolower($productName));
            $pocketSilhouette = collect(is_array($addPocketSilhouette) ? $addPocketSilhouette : [$addPocketSilhouette])
                ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                ->map(fn (string $value): string => trim($value))
                ->implode(' / ');
            $isMermaidPocketException = $pocketSilhouette !== ''
                && preg_match('/\b(?:mermaid|trumpet)\b/i', $pocketSilhouette) === 1;
            $promisesPocketsForException = $isMermaidPocketException
                && preg_match('/\b(?:can|could|will|would|happy|delighted)\b.{0,35}\badd\b.{0,20}\bpockets?\b|\badd\b.{0,20}\bpockets?\b.{0,35}\b(?:free|no\s+extra\s+charge|order\s+notes?)\b/i', $answer) === 1;
            $missingPocketInstructions = $pocketSilhouette !== ''
                && ! $isMermaidPocketException
                && (! preg_match('/\border\s+notes?\b/i', $output['answer_en'])
                    || ! preg_match('/\b(?:free|no\s+(?:additional|extra)\s+(?:fee|cost|charge)|at\s+no\s+(?:additional|extra)\s+(?:fee|cost|charge))\b/i', $output['answer_en']));
            $inventsMissingPocketFact = $usesUnknownPocketFallback
                && preg_match('/\b(?:does\s+not|doesn\'t|do\s+not|don\'t|not)\b.{0,30}\b(?:come\s+with|have|include)\b.{0,20}\bpockets?\b/i', $output['answer_en']) === 1;
            $omitsUnknownPocketDisclosure = $usesUnknownPocketFallback
                && preg_match('/\b(?:not|isn\'t|aren\'t)\b.{0,35}\b(?:listed|shown|confirmed|specified)\b|\b(?:details?|listing|attributes?)\b.{0,35}\b(?:do\s+not|don\'t|does\s+not|doesn\'t)\b.{0,20}\b(?:confirm|list|show|specify)\b/i', $output['answer_en']) !== 1;
            $leavesPocketFallbackUnresolved = $usesUnknownPocketFallback
                && collect($output['unresolved_facts'])->contains(fn (string $fact): bool => preg_match('/pockets?/i', $fact) === 1);
            if (! $mentionsUnselectedRush
                && ! $addsUnneededSupportReferral
                && ! $asksForDestinationReply
                && ! $repeatsProductTitle
                && ! $promisesPocketsForException
                && ! $missingPocketInstructions
                && ! $inventsMissingPocketFact
                && ! $omitsUnknownPocketDisclosure
                && ! $leavesPocketFallbackUnresolved
            ) {
                $completion['usage'] = [...$usage, 'semantic_retries' => $attempt];

                return [$completion, $output];
            }
            $violations = array_filter([
                $mentionsUnselectedRush ? 'introduced Rush or Fast Dispatch without a selected request_rush_order intent' : null,
                $addsUnneededSupportReferral ? 'added a support referral even though the verified Custom Size workflow directly answers the question' : null,
                $asksForDestinationReply ? 'asked the customer to reply with a destination even though public product Q&A is a single-turn channel and checkout can show the destination-specific ETA' : null,
                $repeatsProductTitle ? 'repeated the full product title even though the question is already attached to that product' : null,
                $promisesPocketsForException ? 'promised added pockets for a Mermaid/Trumpet item even though that silhouette is the verified exception' : null,
                $missingPocketInstructions ? 'did not state both that adding pockets is free and that the customer should write the request in Order Notes' : null,
                $inventsMissingPocketFact ? 'claimed the item does not have pockets even though the current product facts do not establish that' : null,
                $omitsUnknownPocketDisclosure ? 'did not disclose that the current product details do not confirm whether pockets are included by default' : null,
                $leavesPocketFallbackUnresolved ? 'left the default pocket attribute unresolved even though the verified add-pocket fallback provides a complete safe response' : null,
            ]);
            if ($attempt === 1) {
                throw new RuntimeException('Draft failed semantic policy checks after retry: '.implode('; ', $violations).'.');
            }
            $userPrompt .= "\n\nThe previous draft ".implode(' and ', $violations).'. Remove that topic completely and return a corrected complete JSON object.';
        }

        throw new RuntimeException('Draft generation failed after semantic validation retries.');
    }

    private function reportsCustomSizeFailure(string $question): bool
    {
        return preg_match('/\bcustom\s+size\b.{0,60}\b(?:missing|not\s+(?:appear|show|available)|cannot\s+find|can\'t\s+find)\b/i', $question) === 1
            || preg_match('/\b(?:error|not\s+working|won\'t\s+load|cannot\s+load|can\'t\s+load)\b/i', $question) === 1;
    }

    /** @param list<string> $unresolved
     * @param  list<string>  $knownMissing
     * @return list<string>
     */
    private function canonicalizeUnresolvedFacts(array $unresolved, array $knownMissing): array
    {
        return collect($unresolved)->map(function (string $fact) use ($knownMissing): string {
            if (in_array('country_code_for_eta', $knownMissing, true)
                && preg_match('/(?:country|destination).*(?:eta|arrival)|(?:eta|arrival).*country/i', $fact) === 1
            ) {
                return 'country_code_for_eta';
            }
            if (in_array('country_code_for_shipping', $knownMissing, true)
                && preg_match('/(?:country|destination).*shipping|shipping.*country/i', $fact) === 1
            ) {
                return 'country_code_for_shipping';
            }

            return $fact;
        })->unique()->values()->all();
    }

    private function blocked(ProductQuestion $question, string $inputHash, array $plan, array $flags, array $completion): ProductQuestionDraft
    {
        return ProductQuestionDraft::create([
            'product_question_id' => $question->id,
            'status' => 'blocked',
            'model' => $this->client->model(),
            'prompt_version' => ProductQuestionDraftPrompt::VERSION,
            'intents' => $plan['intents'],
            'knowledge_article_ids' => [],
            'facts' => [],
            'fact_sources' => [],
            'missing_facts' => ['verified_policy'],
            'safety_flags' => $flags,
            'customer_language' => $plan['customer_language'],
            'recommended_visible' => false,
            'requires_human_review' => true,
            'input_hash' => $inputHash,
            'usage' => $completion['usage'],
            'provider_request_id' => $completion['request_id'],
        ]);
    }

    private function redact(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[EMAIL]', $text) ?? $text;
        $text = preg_replace('/(?:\+?\d[\d .()\/-]{7,}\d)/', '[PHONE]', $text) ?? $text;
        $text = preg_replace('/\border\s*(?:number|no\.?|id|#)\s*[:#-]?\s*[A-Z0-9-]{4,}\b/i', 'order [ORDER_ID]', $text) ?? $text;

        return $text;
    }

    private function containsPrivateIdentifier(string $answer, ProductQuestion $question): bool
    {
        if ($question->email !== '' && str_contains(mb_strtolower($answer), mb_strtolower($question->email))) {
            return true;
        }

        return preg_match('/\b(?:order\s*(?:number|no\.?|#)?\s*)?[A-Z]{2,8}\d{6,}(?:-\d+)?\b/i', $answer) === 1;
    }

    /** @param list<string> $missingFacts */
    private function hasBlockingMissingFacts(array $missingFacts): bool
    {
        return collect($missingFacts)->contains(
            fn (string $fact): bool => ! in_array($fact, ['country_code_for_eta', 'country_code_for_shipping'], true),
        );
    }

    /** @return array{output:array<string,mixed>,usage:array<string,mixed>,request_id:?string} */
    private function structuredCompletion(string $systemPrompt, string $userPrompt): array
    {
        try {
            return $this->client->structuredCompletion($systemPrompt, $userPrompt);
        } catch (RuntimeException $exception) {
            if (! str_starts_with($exception->getMessage(), 'MiniMax returned invalid JSON')
                && ! str_starts_with($exception->getMessage(), 'MiniMax structured output')) {
                throw $exception;
            }
        }

        return $this->client->structuredCompletion($systemPrompt, $userPrompt);
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn (mixed $item): string => trim((string) $item), $value)));
    }

    private function usage(array $first, array $second): array
    {
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $field) {
            $second[$field] = (int) ($first[$field] ?? 0) + (int) ($second[$field] ?? 0);
        }

        return $second;
    }
}
