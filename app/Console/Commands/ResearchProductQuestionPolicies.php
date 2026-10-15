<?php

namespace App\Console\Commands;

use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnalysis;
use App\Models\ProductQuestionPolicyRun;
use App\Services\AI\MiniMaxClient;
use App\Services\ProductQuestions\ProductQuestionPolicyPrompt;
use App\Services\ProductQuestions\ProductQuestionPolicySynthesizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class ResearchProductQuestionPolicies extends Command
{
    protected $signature = 'product-questions:research-policies
        {--site= : Site key}
        {--since-days=150 : Historical window}
        {--limit=500 : Maximum total evidence records}
        {--per-intent=20 : Maximum evidence records selected per intent}
        {--intent= : Only synthesize this controlled intent key}
        {--seed=qa-policy-v1 : Stable sample seed}
        {--batch= : Unique batch key}
        {--source-assumption= : Optional owner-provided provenance assumption}';

    protected $description = 'Synthesize reviewable policy and action-flow candidates from existing classified Q&A';

    public function handle(
        MiniMaxClient $client,
        ProductQuestionPolicySynthesizer $synthesizer,
    ): int {
        if (! $client->isConfigured()) {
            $this->error('MiniMax is not configured.');

            return self::FAILURE;
        }
        $site = CommerceSite::query()->where('enabled', true)->where('site_key', trim((string) $this->option('site')))->first();
        $sinceDays = (int) $this->option('since-days');
        $limit = (int) $this->option('limit');
        $perIntent = (int) $this->option('per-intent');
        $intent = trim((string) $this->option('intent'));
        $seed = trim((string) $this->option('seed'));
        if ($site === null || $sinceDays < 1 || $sinceDays > 3650 || $limit < 1 || $limit > 500 || $perIntent < 1 || $perIntent > 20 || $seed === '' || ($intent !== '' && ! in_array($intent, ProductQuestionAnalysis::INTENTS, true))) {
            $this->error('请提供有效的 --site、--since-days、--limit、--per-intent、--intent 和 --seed。');

            return self::INVALID;
        }
        $batchKey = trim((string) $this->option('batch')) ?: 'qa-policy-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(4));
        $windowEnd = now();
        $windowStart = $windowEnd->copy()->subDays($sinceDays);
        $run = ProductQuestionPolicyRun::create([
            'commerce_site_id' => $site->id,
            'batch_key' => $batchKey,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'sample_limit' => $limit,
            'selection_seed' => $seed,
            'source_assumption' => trim((string) $this->option('source-assumption')) ?: null,
            'status' => 'running',
            'provider' => 'minimax',
            'model' => $client->model(),
            'prompt_version' => ProductQuestionPolicyPrompt::VERSION,
            'started_at' => now(),
        ]);
        $questions = ProductQuestion::query()
            ->where('commerce_site_id', $site->id)
            ->where('remote_present', true)
            ->where('reply_status', 'answered')
            ->where('remote_created_at', '>=', $windowStart)
            ->whereHas('analysis', function ($analysis) use ($intent): void {
                $analysis->where('work_queue', 'policy_review')->whereNotNull('intent_key');
                if ($intent !== '') {
                    $analysis->where('intent_key', $intent);
                }
            })
            ->with(['site', 'analysis'])
            ->get();
        $questions = $this->balancedSample($questions, $seed, $perIntent, $limit);
        $run->update(['selected_count' => $questions->count()]);

        $run->update(['analyzed_count' => $questions->count(), 'failed_count' => 0]);

        try {
            $analyses = $questions->map(fn (ProductQuestion $question) => $question->fresh()->analysis)->filter()->values();
            $result = $synthesizer->synthesize($run->fresh('site'), $analyses);
            $hasErrors = $result['errors'] !== [];
            $run->update([
                'status' => $hasErrors ? 'completed_with_errors' : 'completed',
                'candidate_count' => $result['candidates'],
                'usage' => $result['usage'],
                'error_message' => $result['errors'] === [] ? null : mb_substr(implode(' | ', $result['errors']), 0, 5000),
                'finished_at' => now(),
            ]);
            $this->info("完成：{$result['candidates']} 个策略候选，按意图选择 {$questions->count()} 条既有分析，".count($result['errors'])." 个归纳分组失败。批次 {$batchKey}");

            return $hasErrors ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                'finished_at' => now(),
            ]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  Collection<int, ProductQuestion>  $questions
     * @return Collection<int, ProductQuestion>
     */
    private function balancedSample(Collection $questions, string $seed, int $perIntent, int $limit): Collection
    {
        return $questions
            ->groupBy(fn (ProductQuestion $question): string => (string) $question->analysis->intent_key)
            ->sortKeys()
            ->flatMap(fn (Collection $group): Collection => $group
                ->sortBy(fn (ProductQuestion $question): string => hash('sha256', $seed.':'.$question->id))
                ->take($perIntent))
            ->take($limit)
            ->values();
    }
}
