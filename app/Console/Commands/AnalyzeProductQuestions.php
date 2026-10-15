<?php

namespace App\Console\Commands;

use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Services\ProductQuestions\ProductQuestionAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AnalyzeProductQuestions extends Command
{
    protected $signature = 'product-questions:analyze
        {--site= : Site key; required when more than one enabled site exists}
        {--limit=30 : Maximum records to analyze}
        {--seed=qa-pilot-v1 : Stable sample seed}
        {--reply-status=answered : all, answered, or unanswered}
        {--question= : Analyze one external question ID}
        {--force : Reanalyze unchanged candidate results}';

    protected $description = 'Classify historical product questions and assess observed reply patterns';

    public function handle(ProductQuestionAnalyzer $analyzer): int
    {
        if (! $analyzer->isConfigured()) {
            $this->error('MiniMax is not configured. Set MINIMAX_API_KEY, MINIMAX_API_URL and MINIMAX_MODEL.');

            return self::FAILURE;
        }

        try {
            $options = validator([
                'limit' => (int) $this->option('limit'),
                'seed' => trim((string) $this->option('seed')),
                'reply_status' => trim((string) $this->option('reply-status')),
                'question' => $this->option('question') === null ? null : (int) $this->option('question'),
            ], [
                'limit' => ['required', 'integer', 'min:1', 'max:5000'],
                'seed' => ['required', 'string', 'max:150'],
                'reply_status' => ['required', Rule::in(['all', 'answered', 'unanswered'])],
                'question' => ['nullable', 'integer', 'min:1'],
            ])->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }

        $site = $this->site();
        if ($site === null) {
            return self::INVALID;
        }
        $questions = $this->select($site, $options);
        if ($questions->isEmpty()) {
            $this->warn('没有符合条件的问答。');

            return self::SUCCESS;
        }

        $this->info("站点 {$site->site_key}：准备分析 {$questions->count()} 条问答，模型 {$analyzer->model()}");
        $counts = ['completed' => 0, 'skipped_unchanged' => 0, 'skipped_reviewed' => 0, 'failed' => 0];
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        foreach ($questions as $index => $question) {
            $result = $analyzer->analyze($question, (bool) $this->option('force'));
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
            foreach (array_keys($usage) as $field) {
                $usage[$field] += (int) ($result['usage'][$field] ?? 0);
            }
            $this->line(sprintf(
                '[%d/%d] QA #%d | %s%s',
                $index + 1,
                $questions->count(),
                $question->external_id,
                $result['status'],
                $result['error'] ? ' | '.$result['error'] : '',
            ));
        }

        $this->newLine();
        $this->line(json_encode(
            ['site' => $site->site_key, 'selected' => $questions->count(), ...$counts, 'usage' => $usage],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function site(): ?CommerceSite
    {
        $query = CommerceSite::query()->where('enabled', true);
        $key = trim((string) $this->option('site'));
        if ($key !== '') {
            $site = $query->where('site_key', $key)->first();
            if ($site === null) {
                $this->error("找不到已启用站点：{$key}");
            }

            return $site;
        }
        if ($query->count() !== 1) {
            $this->error('存在零个或多个已启用站点，请使用 --site 指定站点。');

            return null;
        }

        return $query->first();
    }

    /** @param array<string, mixed> $options
     * @return Collection<int, ProductQuestion>
     */
    private function select(CommerceSite $site, array $options): Collection
    {
        $query = ProductQuestion::query()
            ->where('commerce_site_id', $site->id)
            ->where('remote_present', true)
            ->with(['site', 'analysis']);
        if ($options['question'] !== null) {
            return $query->where('external_id', $options['question'])->get();
        }
        if ($options['reply_status'] !== 'all') {
            $query->where('reply_status', $options['reply_status']);
        }

        return $query->get()->sortBy(
            fn (ProductQuestion $question): string => ($question->analysis === null ? '0:' : '1:')
                .hash('sha256', $options['seed'].':'.$question->id),
        )->take($options['limit'])->values();
    }
}
