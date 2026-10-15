<?php

namespace App\Services\ProductQuestions;

use App\Models\CommerceProduct;
use App\Models\ProductQuestionAnalysis;
use App\Models\ProductQuestionPolicyCandidate;
use App\Models\ProductQuestionPolicyRun;
use App\Services\AI\MiniMaxClient;
use Illuminate\Support\Collection;
use RuntimeException;

class ProductQuestionPolicySynthesizer
{
    public function __construct(
        private readonly MiniMaxClient $client,
        private readonly ProductQuestionPolicyPrompt $prompt,
        private readonly ProductQuestionPolicyValidator $validator,
    ) {}

    /** @param Collection<int, ProductQuestionAnalysis> $analyses
     * @return array{candidates:int, usage:array<string,mixed>, errors:list<string>}
     */
    public function synthesize(ProductQuestionPolicyRun $run, Collection $analyses): array
    {
        $usage = [];
        $errors = [];
        foreach ($analyses->groupBy('intent_key') as $intentKey => $intentAnalyses) {
            foreach ($intentAnalyses->chunk(20) as $chunk) {
                $category = $chunk->countBy('primary_category')->sortDesc()->keys()->first();
                $context = [
                    'site_key' => $run->site->site_key,
                    'source_assumption' => $run->source_assumption,
                    'category' => $category,
                    'intent_key' => $intentKey,
                    'historical_qa' => $this->evidence($chunk),
                ];
                try {
                    $result = $this->complete(
                        $this->prompt->system(),
                        $this->prompt->user($context),
                        $chunk->pluck('product_question_id')->all(),
                        (string) $intentKey,
                        (string) $category,
                    );
                    $usage = $this->usage($usage, $result['usage']);
                    foreach ($result['strategies'] as $strategy) {
                        $this->mergeCandidate($run, $strategy);
                    }
                } catch (RuntimeException $exception) {
                    $errors[] = "{$intentKey}: {$exception->getMessage()}";
                }
            }
        }

        $candidateCount = $run->candidates()->count();
        if ($candidateCount === 0 && $errors !== []) {
            throw new RuntimeException(implode(' | ', $errors));
        }

        return ['candidates' => $candidateCount, 'usage' => $usage, 'errors' => $errors];
    }

    /** @return list<array<string, mixed>> */
    private function evidence(Collection $analyses): array
    {
        $analyses->each(fn (ProductQuestionAnalysis $analysis) => $analysis->loadMissing('question'));
        $products = CommerceProduct::query()
            ->where('commerce_site_id', $analyses->first()->question->commerce_site_id)
            ->whereIn('sn', $analyses->pluck('question.sn')->filter()->unique())
            ->get()
            ->keyBy('sn');

        return $analyses->map(function (ProductQuestionAnalysis $analysis) use ($products): array {
            $question = $analysis->question;
            $product = $products->get($question->sn);

            return [
                'question_id' => $question->id,
                'external_id' => $question->external_id,
                'product_sn' => $question->sn,
                'question' => trim($question->question),
                'observed_answer' => trim(html_entity_decode(strip_tags((string) $question->answer))),
                'created_at' => $question->remote_created_at?->toDateString(),
                'reply_source' => $question->reply_source,
                'analysis' => [
                    'customer_intent' => $analysis->customer_intent,
                    'required_facts' => $analysis->required_facts,
                    'missing_facts' => $analysis->missing_facts,
                    'response_moves' => $analysis->response_moves,
                    'reply_pattern' => $analysis->reply_pattern,
                    'risk_level' => $analysis->risk_level,
                    'answer_assessment' => $analysis->answer_assessment,
                ],
                'current_product_context' => $product ? [
                    'name' => $product->name,
                    'attributes' => data_get($product->product_detail, 'attributes', []),
                    'colors' => data_get($product->options, 'colors', []),
                    'sizes' => data_get($product->options, 'sizes', []),
                    'services' => data_get($product->options, 'additional_services', []),
                    'synced_at' => $product->synced_at?->toIso8601String(),
                ] : null,
            ];
        })->values()->all();
    }

    /** @return array{strategies:list<array<string,mixed>>, usage:array<string,mixed>} */
    private function complete(
        string $systemPrompt,
        string $userPrompt,
        array $allowedIds,
        string $expectedScenarioKey,
        string $expectedCategory,
    ): array {
        $usage = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $completion = null;
            try {
                $completion = $this->client->structuredCompletion($systemPrompt, $userPrompt);
                $usage = $this->usage($usage, $completion['usage']);

                return [
                    'strategies' => $this->validator->validate(
                        $completion['output'],
                        $allowedIds,
                        $expectedScenarioKey,
                        $expectedCategory,
                    ),
                    'usage' => [...$usage, 'validation_retries' => $attempt],
                ];
            } catch (RuntimeException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
                $userPrompt .= "\n\nThe previous output failed deterministic validation: {$exception->getMessage()} Return corrected complete JSON using only these question IDs: ".json_encode($allowedIds, JSON_THROW_ON_ERROR);
                if ($completion !== null) {
                    $userPrompt .= ' Previous JSON: '.json_encode($completion['output'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
            }
        }

        throw new RuntimeException('Policy synthesis failed after validation retries.');
    }

    /** @param array<string, mixed> $strategy */
    private function mergeCandidate(ProductQuestionPolicyRun $run, array $strategy): void
    {
        $candidate = ProductQuestionPolicyCandidate::firstOrNew([
            'product_question_policy_run_id' => $run->id,
            'scenario_key' => $strategy['scenario_key'],
        ]);
        if (! $candidate->exists) {
            $candidate->fill([
                'commerce_site_id' => $run->commerce_site_id,
                ...$strategy,
                'review_status' => 'candidate',
            ])->save();

            return;
        }
        foreach (['required_facts', 'policy_rules', 'decision_tree', 'reply_strategy', 'reply_templates', 'action_steps', 'prohibited_claims', 'escalation_rules', 'evidence_question_ids', 'conflicts', 'open_questions'] as $field) {
            $candidate->{$field} = array_values(array_unique([
                ...($candidate->{$field} ?: []),
                ...$strategy[$field],
            ], SORT_REGULAR));
        }
        $candidate->confidence = min((float) $candidate->confidence, (float) $strategy['confidence']);
        $candidate->save();
    }

    private function usage(array $first, array $second): array
    {
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens', 'validation_retries'] as $field) {
            $second[$field] = (int) ($first[$field] ?? 0) + (int) ($second[$field] ?? 0);
        }

        return $second;
    }
}
