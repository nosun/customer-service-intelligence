<?php

namespace App\Services\ProductQuestions;

use App\Models\CommerceProduct;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnalysis;
use App\Models\ProductQuestionAnalysisRun;
use App\Services\AI\MiniMaxClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProductQuestionAnalyzer
{
    public const VERSION = 'product-question-analyzer-v1';

    public function __construct(
        private readonly MiniMaxClient $client,
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

    /** @return array{status:string, analysis_id:?int, run_id:?int, error:?string, usage:array<string,mixed>} */
    public function analyze(ProductQuestion $question, bool $force = false): array
    {
        $context = $this->context($question->loadMissing('site'));
        $inputHash = hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $existing = $question->analysis;
        if ($existing !== null && $existing->review_status !== 'candidate') {
            return $this->result('skipped_reviewed', $existing->id);
        }
        if (! $force && $existing?->input_hash === $inputHash && $existing->intent_key !== null) {
            return $this->result('skipped_unchanged', $existing->id);
        }

        $systemPrompt = $this->prompt->system();
        $userPrompt = $this->prompt->user($context);
        $run = ProductQuestionAnalysisRun::create([
            'product_question_id' => $question->id,
            'provider' => 'minimax',
            'model' => $this->client->model(),
            'prompt_version' => ProductQuestionAnalysisPrompt::VERSION,
            'analyzer_version' => self::VERSION,
            'input_hash' => $inputHash,
            'prompt_hash' => hash('sha256', $systemPrompt."\n".$userPrompt),
            'status' => 'running',
            'started_at' => now(),
        ]);
        $startedAt = hrtime(true);
        $completion = null;

        try {
            $completion = $this->complete($systemPrompt, $userPrompt, filled($question->answer));
            $output = $completion['output'];
            $analysis = DB::transaction(fn (): ProductQuestionAnalysis => ProductQuestionAnalysis::updateOrCreate(
                ['product_question_id' => $question->id],
                [
                    ...$output,
                    'review_status' => 'candidate',
                    'review_notes' => null,
                    'analyzer_version' => self::VERSION,
                    'input_hash' => $inputHash,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                ],
            ));
            $run->update([
                'status' => 'completed',
                'structured_output' => $output,
                'usage' => $completion['usage'],
                'provider_request_id' => $completion['request_id'],
                'duration_ms' => $this->duration($startedAt),
                'finished_at' => now(),
            ]);

            return $this->result('completed', $analysis->id, $run->id, usage: $completion['usage']);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'structured_output' => $completion['output'] ?? null,
                'usage' => $completion['usage'] ?? null,
                'provider_request_id' => $completion['request_id'] ?? null,
                'duration_ms' => $this->duration($startedAt),
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                'finished_at' => now(),
            ]);

            return $this->result('failed', $existing?->id, $run->id, $exception->getMessage(), $completion['usage'] ?? []);
        }
    }

    /** @return array<string, mixed> */
    public function context(ProductQuestion $question): array
    {
        $productSnapshot = CommerceProduct::query()
            ->where('commerce_site_id', $question->commerce_site_id)
            ->where('sn', $question->sn)
            ->where('sync_status', 'completed')
            ->first();

        return [
            'record_id' => $question->id,
            'site_key' => $question->site?->site_key,
            'product_sn' => $question->sn,
            'product' => $question->product,
            'product_catalog' => $productSnapshot ? [
                'product' => $productSnapshot->product,
                'type' => data_get($productSnapshot->product, 'type'),
                'sub_type' => data_get($productSnapshot->product, 'sub_type'),
                'customizable' => data_get($productSnapshot->product, 'customizable'),
                'attributes' => data_get($productSnapshot->product_detail, 'attributes', []),
                'images' => collect($productSnapshot->images)->map(fn (array $image): array => [
                    'style' => $image['style'] ?? null,
                    'color' => $image['color'] ?? null,
                    'is_main' => (bool) ($image['is_main'] ?? false),
                    'url' => $image['url'] ?? null,
                ])->all(),
                'colors' => data_get($productSnapshot->options, 'colors', []),
                'sizes' => data_get($productSnapshot->options, 'sizes', []),
                'stock_specs' => data_get($productSnapshot->options, 'stock_specs', []),
                'additional_services' => data_get($productSnapshot->options, 'additional_services', []),
                'editable_fields' => data_get($productSnapshot->options, 'editable_fields', []),
                'custom_size_input_available' => $this->customSizeInputAvailable($productSnapshot),
                'custom_size_entry_method' => $this->customSizeInputAvailable($productSnapshot) === true ? 'size_selector' : null,
                'size_chart_available' => data_get($productSnapshot->options, 'size_chart', []) !== [],
                'synced_at' => $productSnapshot->synced_at?->toIso8601String(),
            ] : null,
            'question_type' => $question->type_text,
            'question' => trim($question->question),
            'answer' => $question->answer ? trim(html_entity_decode(strip_tags($question->answer))) : null,
            'reply_status' => $question->reply_status,
            'visible' => $question->visible,
            'helpful' => $question->helpful,
            'created_at' => $question->remote_created_at?->toIso8601String(),
            'updated_at' => $question->remote_updated_at?->toIso8601String(),
        ];
    }

    private function customSizeInputAvailable(CommerceProduct $product): ?bool
    {
        $configured = data_get($product->options, 'custom_size_available');
        if (is_bool($configured)) {
            return $configured;
        }

        if (data_get($product->product, 'type') === 'dress'
            && in_array(data_get($product->product, 'customizable'), [true, 1, '1'], true)
        ) {
            return true;
        }

        return in_array(data_get($product->product, 'customizable'), [false, 0, '0'], true) ? false : null;
    }

    /** @return array{output:array<string,mixed>, usage:array<string,mixed>, request_id:?string} */
    private function complete(string $systemPrompt, string $userPrompt, bool $hasAnswer): array
    {
        $usage = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $completion = null;
            try {
                $completion = $this->client->structuredCompletion($systemPrompt, $userPrompt);
                $usage = $this->usage($usage, $completion['usage']);
                $output = $completion['output'];
                if (! $hasAnswer) {
                    $output['reply_pattern'] = 'unanswered';
                    $output['answer_summary'] = null;
                }
                $completion['output'] = $this->validator->validate($output, $hasAnswer);
                $completion['usage'] = [...$usage, 'validation_retries' => $attempt];

                return $completion;
            } catch (RuntimeException $exception) {
                if ($attempt === 1) {
                    throw $exception;
                }
                $userPrompt .= "\n\nThe previous JSON failed validation: {$exception->getMessage()} Return a corrected complete JSON object.";
                if ($completion !== null) {
                    $userPrompt .= ' Previous JSON: '.json_encode($completion['output'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
            }
        }

        throw new RuntimeException('Product question analysis failed after validation retries.');
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

    private function result(string $status, ?int $analysisId, ?int $runId = null, ?string $error = null, array $usage = []): array
    {
        return [
            'status' => $status,
            'analysis_id' => $analysisId,
            'run_id' => $runId,
            'error' => $error,
            'usage' => $usage,
        ];
    }
}
