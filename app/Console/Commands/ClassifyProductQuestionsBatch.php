<?php

namespace App\Console\Commands;

use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnalysis;
use App\Services\ProductQuestions\ProductQuestionBatchAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClassifyProductQuestionsBatch extends Command
{
    protected $signature = 'product-questions:classify-batch
        {--site=dorriswedding : Site key}
        {--days=150 : Only questions created within this many days}
        {--limit=5000 : Maximum records selected}
        {--batch-size=10 : Records sent to MiniMax per request}
        {--shards=1 : Split selection into stable ID shards for parallel workers}
        {--shard=0 : Zero-based shard handled by this worker}
        {--seed=qa-routing-v1 : Stable selection seed}
        {--reply-status=all : all, answered, or unanswered}
        {--work-queue= : Only reanalyze questions currently assigned to this work queue}
        {--force : Reanalyze unchanged candidate results}';

    protected $description = 'Batch classify recent product questions into intent and operational work queues';

    public function handle(ProductQuestionBatchAnalyzer $analyzer): int
    {
        if (! $analyzer->isConfigured()) {
            $this->error('MiniMax is not configured. Set MINIMAX_API_KEY, MINIMAX_API_URL and MINIMAX_MODEL.');

            return self::FAILURE;
        }

        try {
            $options = validator([
                'days' => (int) $this->option('days'),
                'limit' => (int) $this->option('limit'),
                'batch_size' => (int) $this->option('batch-size'),
                'shards' => (int) $this->option('shards'),
                'shard' => (int) $this->option('shard'),
                'seed' => trim((string) $this->option('seed')),
                'reply_status' => trim((string) $this->option('reply-status')),
                'work_queue' => trim((string) $this->option('work-queue')),
            ], [
                'days' => ['required', 'integer', 'min:1', 'max:730'],
                'limit' => ['required', 'integer', 'min:1', 'max:10000'],
                'batch_size' => ['required', 'integer', 'min:1', 'max:20'],
                'shards' => ['required', 'integer', 'min:1', 'max:8'],
                'shard' => ['required', 'integer', 'min:0', 'max:7'],
                'seed' => ['required', 'string', 'max:150'],
                'reply_status' => ['required', Rule::in(['all', 'answered', 'unanswered'])],
                'work_queue' => ['nullable', Rule::in(array_keys(ProductQuestionAnalysis::WORK_QUEUES))],
            ])->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }
        if ($options['shard'] >= $options['shards']) {
            $this->error('--shard 必须小于 --shards。');

            return self::INVALID;
        }

        $site = CommerceSite::query()
            ->where('enabled', true)
            ->where('site_key', trim((string) $this->option('site')))
            ->first();
        if ($site === null) {
            $this->error('找不到指定的已启用站点。');

            return self::INVALID;
        }

        $questions = $this->select($site, $options);
        if ($questions->isEmpty()) {
            $this->warn('没有符合条件的问答。');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '站点 %s：近 %d 天共选择 %d 条，每批 %d 条，模型 %s',
            $site->site_key,
            $options['days'],
            $questions->count(),
            $options['batch_size'],
            $analyzer->model(),
        ));
        $counts = ['completed' => 0, 'skipped_unchanged' => 0, 'skipped_reviewed' => 0, 'failed' => 0];
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $chunks = $questions->chunk($options['batch_size']);
        foreach ($chunks as $index => $chunk) {
            $result = $analyzer->analyzeResilient($chunk->values(), (bool) $this->option('force'));
            foreach ($result['results'] as $item) {
                $counts[$item['status']] = ($counts[$item['status']] ?? 0) + 1;
            }
            foreach (array_keys($usage) as $field) {
                $usage[$field] += (int) ($result['usage'][$field] ?? 0);
            }
            $this->line(sprintf(
                '[%d/%d] completed=%d skipped=%d failed=%d',
                $index + 1,
                $chunks->count(),
                $counts['completed'],
                $counts['skipped_unchanged'] + $counts['skipped_reviewed'],
                $counts['failed'],
            ));
        }

        $this->newLine();
        $this->line(json_encode(
            ['site' => $site->site_key, 'days' => $options['days'], 'selected' => $questions->count(), ...$counts, 'usage' => $usage],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $options
     * @return Collection<int, ProductQuestion>
     */
    private function select(CommerceSite $site, array $options): Collection
    {
        $query = ProductQuestion::query()
            ->where('commerce_site_id', $site->id)
            ->where('remote_present', true)
            ->where('remote_created_at', '>=', now()->subDays($options['days']))
            ->whereRaw('product_questions.id % ? = ?', [$options['shards'], $options['shard']])
            ->with(['site', 'analysis']);
        if ($options['reply_status'] !== 'all') {
            $query->where('reply_status', $options['reply_status']);
        }
        if ($options['work_queue'] !== '') {
            $query->whereHas('analysis', fn ($analysis) => $analysis->where('work_queue', $options['work_queue']));
        }

        return $query->get()->sortBy(
            fn (ProductQuestion $question): string => ($question->analysis?->analyzer_version !== ProductQuestionBatchAnalyzer::VERSION ? '0:' : '1:')
                .hash('sha256', $options['seed'].':'.$question->id),
        )->take($options['limit'])->values();
    }
}
