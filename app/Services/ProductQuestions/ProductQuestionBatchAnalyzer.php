<?php

namespace App\Services\ProductQuestions;

use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnalysis;
use App\Models\ProductQuestionAnalysisRun;
use App\Services\AI\MiniMaxClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProductQuestionBatchAnalyzer
{
    public const VERSION = 'product-question-batch-analyzer-v12';

    private const CATALOG_INTENTS = [
        'ask_product_details', 'ask_product_photos', 'compare_products', 'ask_weight', 'ask_pockets',
        'ask_lining', 'ask_dress_length', 'ask_built_in_bra', 'ask_closure', 'ask_train', 'ask_available_colors',
        'ask_color_match', 'ask_color_photos', 'ask_fabric', 'ask_swatch', 'ask_size_chart',
        'ask_fit', 'ask_plus_size', 'ask_in_stock', 'ask_restock',
    ];

    private const FACTORY_INTENTS = [
        'request_custom_color' => 'custom_color',
        'request_custom_length' => 'custom_length',
        'request_add_pockets' => 'add_pockets',
        'request_add_sleeves' => 'add_sleeves',
        'request_remove_sleeves' => 'remove_sleeves',
        'request_change_neckline' => 'change_neckline',
        'request_change_back' => 'change_back',
        'request_remove_train' => 'remove_train',
        'request_add_train' => 'add_train',
        'request_mix_designs' => 'mix_design_elements',
        'request_other_customization' => 'other_customization',
    ];

    private const POLICY_INTENTS = [
        'ask_product_origin', 'ask_production_time', 'request_rush_order', 'ask_delivery_time', 'ask_shipping_options',
        'ask_tracking', 'ask_price', 'ask_discount', 'ask_payment', 'ask_guarantee',
        'ask_return_policy', 'ask_cancellation_policy', 'ask_refund',
        'ask_order_status', 'ask_how_to_order', 'request_contact', 'ask_size_recommendation',
    ];

    public function __construct(
        private readonly MiniMaxClient $client,
        private readonly ProductQuestionAnalyzer $analyzer,
        private readonly ProductQuestionAnalysisPrompt $prompt,
        private readonly ProductQuestionAnalysisValidator $validator,
    ) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function model(): string
    {
        return $this->client->model();
    }

    /**
     * @param  Collection<int, ProductQuestion>  $questions
     * @return array{results:list<array<string,mixed>>, usage:array<string,mixed>, request_id:?string}
     */
    public function analyze(Collection $questions, bool $force = false): array
    {
        $results = [];
        $eligible = collect();

        foreach ($questions as $question) {
            $question->loadMissing(['site', 'analysis']);
            $context = $this->analyzer->context($question);
            $inputHash = hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($question->analysis !== null && $question->analysis->review_status !== 'candidate') {
                $results[] = $this->result($question, 'skipped_reviewed');

                continue;
            }
            if (! $force
                && $question->analysis?->input_hash === $inputHash
                && $question->analysis->intent_key !== null
                && $question->analysis->analyzer_version === self::VERSION
            ) {
                $results[] = $this->result($question, 'skipped_unchanged');

                continue;
            }
            $eligible->push(compact('question', 'context', 'inputHash'));
        }

        if ($eligible->isEmpty()) {
            return ['results' => $results, 'usage' => [], 'request_id' => null];
        }

        $systemPrompt = $this->prompt->batchSystem();
        $userPrompt = $this->prompt->batchUser($eligible->pluck('context')->all());
        $runs = [];
        foreach ($eligible as $item) {
            $question = $item['question'];
            $runs[$question->id] = ProductQuestionAnalysisRun::create([
                'product_question_id' => $question->id,
                'provider' => 'minimax',
                'model' => $this->client->model(),
                'prompt_version' => ProductQuestionAnalysisPrompt::BATCH_VERSION,
                'analyzer_version' => self::VERSION,
                'input_hash' => $item['inputHash'],
                'prompt_hash' => hash('sha256', $systemPrompt."\n".$userPrompt),
                'status' => 'running',
                'started_at' => now(),
            ]);
        }
        $startedAt = hrtime(true);
        $completion = null;

        try {
            $completion = $this->complete($systemPrompt, $userPrompt, $eligible);
            DB::transaction(function () use ($eligible, $completion, $runs, $startedAt): void {
                foreach ($eligible as $index => $item) {
                    $question = $item['question'];
                    $output = $completion['items'][$question->id];
                    ProductQuestionAnalysis::updateOrCreate(
                        ['product_question_id' => $question->id],
                        [
                            ...$output,
                            'review_status' => 'candidate',
                            'review_notes' => null,
                            'analyzer_version' => self::VERSION,
                            'input_hash' => $item['inputHash'],
                            'reviewed_by' => null,
                            'reviewed_at' => null,
                        ],
                    );
                    $runs[$question->id]->update([
                        'status' => 'completed',
                        'structured_output' => $output,
                        'usage' => $index === 0
                            ? [...$completion['usage'], 'shared_batch_size' => $eligible->count()]
                            : ['shared_request' => true, 'shared_batch_size' => $eligible->count()],
                        'provider_request_id' => $completion['request_id'],
                        'duration_ms' => $this->duration($startedAt),
                        'finished_at' => now(),
                    ]);
                }
            });
            foreach ($eligible as $item) {
                $results[] = $this->result($item['question'], 'completed');
            }

            return [
                'results' => $results,
                'usage' => $completion['usage'],
                'request_id' => $completion['request_id'],
            ];
        } catch (Throwable $exception) {
            foreach ($eligible as $item) {
                $question = $item['question'];
                $runs[$question->id]->update([
                    'status' => 'failed',
                    'usage' => $completion['usage'] ?? null,
                    'provider_request_id' => $completion['request_id'] ?? null,
                    'duration_ms' => $this->duration($startedAt),
                    'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                    'finished_at' => now(),
                ]);
                $results[] = $this->result($question, 'failed', $exception->getMessage());
            }

            return [
                'results' => $results,
                'usage' => $completion['usage'] ?? [],
                'request_id' => $completion['request_id'] ?? null,
            ];
        }
    }

    /**
     * Retry records that failed together one by one so one malformed model item does not lose the whole batch.
     *
     * @param  Collection<int, ProductQuestion>  $questions
     * @return array{results:list<array<string,mixed>>, usage:array<string,mixed>, request_id:?string}
     */
    public function analyzeResilient(Collection $questions, bool $force = false): array
    {
        $result = $this->analyze($questions, $force);
        if ($questions->count() <= 1 || ! collect($result['results'])->contains('status', 'failed')) {
            return $result;
        }

        $successful = collect($result['results'])->reject(fn (array $item): bool => $item['status'] === 'failed')->values();
        $usage = $result['usage'];
        foreach ($questions as $question) {
            if (! collect($result['results'])->contains(
                fn (array $item): bool => $item['question_id'] === $question->id && $item['status'] === 'failed',
            )) {
                continue;
            }
            $retry = $this->analyze(collect([$question->fresh(['site', 'analysis'])]), $force);
            $successful = $successful->concat($retry['results']);
            $usage = $this->usage($usage, $retry['usage']);
        }

        return [
            'results' => $successful->values()->all(),
            'usage' => $usage,
            'request_id' => $result['request_id'],
        ];
    }

    /**
     * @param  Collection<int, array{question:ProductQuestion,context:array<string,mixed>,inputHash:string}>  $eligible
     * @return array{items:array<int,array<string,mixed>>,usage:array<string,mixed>,request_id:?string}
     */
    private function complete(string $systemPrompt, string $userPrompt, Collection $eligible): array
    {
        $usage = [];
        $expectedIds = $eligible->pluck('question.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $completion = null;
            try {
                $completion = $this->client->structuredCompletion($systemPrompt, $userPrompt);
                $usage = $this->usage($usage, $completion['usage']);
                $rawItems = $completion['output']['items'] ?? null;
                if (! is_array($rawItems) || ! array_is_list($rawItems)) {
                    throw new RuntimeException('items must be a JSON list.');
                }
                $items = [];
                foreach ($rawItems as $rawItem) {
                    if (! is_array($rawItem) || ! is_numeric($rawItem['record_id'] ?? null)) {
                        throw new RuntimeException('Each batch item requires a numeric record_id.');
                    }
                    $recordId = (int) $rawItem['record_id'];
                    if (isset($items[$recordId])) {
                        throw new RuntimeException("Duplicate record_id {$recordId}.");
                    }
                    unset($rawItem['record_id']);
                    $eligibleItem = $eligible->first(fn (array $item): bool => $item['question']->id === $recordId);
                    $question = $eligibleItem['question'] ?? null;
                    if (! $question instanceof ProductQuestion) {
                        throw new RuntimeException("Unexpected record_id {$recordId}.");
                    }
                    if (! in_array($rawItem['intent_key'] ?? null, ProductQuestionAnalysis::INTENTS, true)) {
                        $rawItem['intent_key'] = 'unclear_or_multi_intent';
                        $rawItem['work_queue'] = 'human_review';
                        $rawItem['answer_source'] = 'human_judgment';
                        $rawItem['factory_capability_key'] = null;
                        $rawItem['factory_question'] = null;
                    }
                    $intent = $rawItem['intent_key'] ?? null;
                    $queue = $rawItem['work_queue'] ?? null;
                    if (in_array($intent, ['unclear_or_multi_intent', 'spam_or_irrelevant'], true)) {
                        $rawItem['work_queue'] = 'human_review';
                        $rawItem['answer_source'] = 'human_judgment';
                        $rawItem['factory_capability_key'] = null;
                        $rawItem['factory_question'] = null;
                    } elseif ($intent === 'request_custom_size'
                        && data_get($eligibleItem, 'context.product_catalog.custom_size_input_available') === true
                        && data_get($eligibleItem, 'context.product_catalog.custom_size_entry_method') === 'size_selector'
                    ) {
                        $rawItem['work_queue'] = 'catalog_answerable';
                    } elseif ($intent === 'request_custom_size') {
                        $rawItem['work_queue'] = 'factory_review';
                        $rawItem['factory_capability_key'] = 'custom_size';
                        $rawItem['factory_question'] = $rawItem['factory_question']
                            ?? '请确认该商品是否支持定制尺码、适用范围、例外、费用以及对制作周期的影响。';
                    } elseif (in_array($intent, self::CATALOG_INTENTS, true)) {
                        $rawItem['work_queue'] = $queue === 'catalog_answerable'
                            && ($eligibleItem['context']['product_catalog'] ?? null) !== null
                            ? 'catalog_answerable'
                            : 'catalog_data_missing';
                    } elseif (isset(self::FACTORY_INTENTS[$intent])) {
                        $rawItem['work_queue'] = 'factory_review';
                        $rawItem['factory_capability_key'] = self::FACTORY_INTENTS[$intent];
                        $rawItem['factory_question'] = $rawItem['factory_question']
                            ?? '请确认该定制能力的适用范围、例外、费用以及对制作周期的影响。';
                    } elseif (in_array($intent, self::POLICY_INTENTS, true)
                        && ($queue !== 'customer_clarification'
                            || ! in_array($intent, ['ask_delivery_time', 'ask_shipping_options', 'ask_order_status'], true))
                    ) {
                        $rawItem['work_queue'] = 'policy_review';
                    }
                    $rawItem['answer_source'] = match ($rawItem['work_queue'] ?? null) {
                        'catalog_answerable' => $intent === 'request_custom_size' ? 'product_and_policy' : 'product_catalog',
                        'catalog_data_missing' => 'product_catalog',
                        'policy_review' => ($rawItem['question_scope'] ?? null) === 'site_policy'
                            ? 'verified_policy'
                            : 'product_and_policy',
                        'factory_review' => 'factory_capability',
                        'customer_clarification' => 'customer_input',
                        default => $rawItem['answer_source'] ?? null,
                    };
                    $rawItem['secondary_categories'] = is_array($rawItem['secondary_categories'] ?? null)
                        ? array_values($rawItem['secondary_categories'])
                        : [];
                    foreach (['required_facts', 'missing_facts'] as $listField) {
                        if (is_string($rawItem[$listField] ?? null) && trim($rawItem[$listField]) !== '') {
                            $rawItem[$listField] = [trim($rawItem[$listField])];
                        } elseif (! is_array($rawItem[$listField] ?? null)) {
                            $rawItem[$listField] = [];
                        } else {
                            $rawItem[$listField] = array_values($rawItem[$listField]);
                        }
                    }
                    $routing = $this->validator->validateRouting($rawItem);
                    $items[$recordId] = [
                        ...$routing,
                        'answerability' => match ($routing['work_queue']) {
                            'catalog_answerable' => $routing['missing_facts'] === [] ? 'direct' : 'needs_business_facts',
                            'catalog_data_missing' => 'needs_business_facts',
                            'policy_review', 'factory_review' => 'needs_business_facts',
                            'customer_clarification' => 'needs_clarification',
                            default => 'escalate',
                        },
                        'response_moves' => [],
                        'reply_pattern' => filled($question->answer) ? 'incomplete' : 'unanswered',
                        'answer_summary' => filled($question->answer) ? '批量分类阶段未评估历史回复。' : null,
                        'answer_assessment' => [
                            'relevance' => 'not_applicable',
                            'completeness' => 'not_applicable',
                            'clarity' => 'not_applicable',
                            'consistency' => 'not_applicable',
                            'policy_safety' => 'not_applicable',
                            'strengths' => [],
                            'gaps' => ['批量分类阶段未评估历史回复'],
                            'prohibited_or_risky_claims' => [],
                        ],
                        'automation_recommendation' => 'human_review',
                        'analysis_summary' => "批量意图分类为 {$routing['intent_key']}，进入 {$routing['work_queue']} 队列；详细回复策略待聚类后分析。",
                    ];
                }
                $actualIds = collect(array_keys($items))->sort()->values()->all();
                if ($actualIds !== $expectedIds) {
                    throw new RuntimeException('Batch output must contain every supplied record_id exactly once.');
                }

                return [
                    'items' => $items,
                    'usage' => [...$usage, 'validation_retries' => $attempt],
                    'request_id' => $completion['request_id'],
                ];
            } catch (RuntimeException $exception) {
                if ($attempt === 1) {
                    throw $exception;
                }
                $userPrompt .= "\n\nThe previous batch JSON failed validation: {$exception->getMessage()} Return corrected complete JSON for every record_id.";
                if ($completion !== null) {
                    $userPrompt .= ' Previous JSON: '.json_encode($completion['output'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
            }
        }

        throw new RuntimeException('Product question batch analysis failed after validation retries.');
    }

    private function duration(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function usage(array $first, array $second): array
    {
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $field) {
            $second[$field] = (int) ($first[$field] ?? 0) + (int) ($second[$field] ?? 0);
        }

        return $second;
    }

    /** @return array{question_id:int,external_id:int,status:string,error:?string} */
    private function result(ProductQuestion $question, string $status, ?string $error = null): array
    {
        return [
            'question_id' => $question->id,
            'external_id' => $question->external_id,
            'status' => $status,
            'error' => $error,
        ];
    }
}
