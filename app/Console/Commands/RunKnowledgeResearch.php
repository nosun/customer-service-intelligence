<?php

namespace App\Console\Commands;

use App\Models\KnowledgeResearchBatch;
use App\Models\KnowledgeResearchBatchItem;
use App\Services\DecisionCases\DecisionCaseBuilder;
use App\Services\KnowledgeResearch\KnowledgeResearchPrompt;
use App\Services\KnowledgeResearch\KnowledgeResearchReport;
use App\Services\KnowledgeResearch\KnowledgeResearchSelector;
use App\Services\KnowledgeResearch\KnowledgeResearchSynthesizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class RunKnowledgeResearch extends Command
{
    protected $signature = 'helpdesk:research:run
        {--limit=10 : Number of Tickets in this batch}
        {--since-days=180 : Only select Tickets created within this many days}
        {--batch= : Unique batch key; generated when omitted}
        {--seed= : Stable selection seed; defaults to the batch key}
        {--retry-batch= : Retry only failed Tickets from a previous research batch}';

    protected $description = 'Analyze a small Ticket batch and persist auditable knowledge and decision-tree candidates';

    public function handle(
        DecisionCaseBuilder $builder,
        KnowledgeResearchSelector $selector,
        KnowledgeResearchSynthesizer $synthesizer,
        KnowledgeResearchReport $report,
    ): int {
        if (! $builder->isConfigured()) {
            $this->error('MiniMax is not configured. Set MINIMAX_API_KEY, MINIMAX_API_URL and MINIMAX_MODEL.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $sinceDays = (int) $this->option('since-days');
        $batchKey = trim((string) $this->option('batch')) ?: 'research-'.now()->format('Ymd-His');
        $seed = trim((string) $this->option('seed')) ?: $batchKey;
        $retryBatchKey = trim((string) $this->option('retry-batch'));
        try {
            validator(compact('limit', 'sinceDays', 'batchKey', 'seed'), [
                'limit' => ['required', 'integer', 'min:1', 'max:100'],
                'sinceDays' => ['required', 'integer', 'min:1', 'max:3650'],
                'batchKey' => ['required', 'alpha_dash:ascii', 'max:100', Rule::unique('knowledge_research_batches', 'batch_key')],
                'seed' => ['required', 'string', 'max:150'],
            ])->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }

        $windowEnd = now();
        $windowStart = $windowEnd->copy()->subDays($sinceDays);
        $batch = KnowledgeResearchBatch::create([
            'batch_key' => $batchKey,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'sample_limit' => $limit,
            'selection_seed' => $seed,
            'status' => 'running',
            'provider' => 'minimax',
            'model' => $builder->model(),
            'prompt_version' => KnowledgeResearchPrompt::VERSION,
            'started_at' => now(),
        ]);
        if ($retryBatchKey !== '') {
            $retryBatch = KnowledgeResearchBatch::where('batch_key', $retryBatchKey)->first();
            if ($retryBatch === null) {
                $batch->delete();
                $this->error("找不到重试批次 {$retryBatchKey}。");

                return self::INVALID;
            }
            $selected = $retryBatch->items()
                ->where('status', 'failed')
                ->with('conversation.analysis')
                ->limit($limit)
                ->get()
                ->pluck('conversation')
                ->filter()
                ->values();
        } else {
            $selected = $selector->select($windowStart, $windowEnd, $limit, $seed);
        }
        foreach ($selected as $conversation) {
            KnowledgeResearchBatchItem::create([
                'knowledge_research_batch_id' => $batch->id,
                'conversation_id' => $conversation->id,
                'status' => 'selected',
            ]);
        }
        $batch->update(['selected_count' => $selected->count()]);
        $this->info("批次 {$batchKey}：选中 {$selected->count()} 张 Ticket，窗口 {$windowStart->toDateString()} 至 {$windowEnd->toDateString()}");

        $completed = 0;
        $failed = 0;
        foreach ($selected as $index => $conversation) {
            $result = $builder->build($conversation);
            $item = KnowledgeResearchBatchItem::where('knowledge_research_batch_id', $batch->id)
                ->where('conversation_id', $conversation->id)
                ->firstOrFail();
            $usable = $result['decision_case_id'] !== null && $result['status'] !== 'failed';
            $item->update([
                'decision_case_id' => $result['decision_case_id'],
                'status' => $usable ? 'completed' : 'failed',
                'error_message' => $result['error'],
            ]);
            $usable ? $completed++ : $failed++;
            $this->line(sprintf(
                '[%d/%d] Ticket %s | %s | Episode %d',
                $index + 1,
                $selected->count(),
                $conversation->external_short_id ?: $conversation->external_id,
                $usable ? $result['status'] : 'failed',
                $result['nodes'],
            ));
        }

        $nodeCount = DB::table('decision_nodes')
            ->join('knowledge_research_batch_items', 'knowledge_research_batch_items.decision_case_id', '=', 'decision_nodes.decision_case_id')
            ->where('knowledge_research_batch_items.knowledge_research_batch_id', $batch->id)
            ->where('knowledge_research_batch_items.status', 'completed')
            ->count();
        $batch->update([
            'completed_count' => $completed,
            'failed_count' => $failed,
            'node_count' => $nodeCount,
        ]);
        if ($selected->isNotEmpty() && $completed === 0) {
            $batch->update([
                'status' => 'failed',
                'error_message' => 'No selected Ticket produced a usable Decision Case.',
                'finished_at' => now(),
            ]);
            $path = $report->write($batch->fresh());
            $this->error('本批没有生成可用的 Decision Case，已保留失败记录供定向重试。');
            $this->line('批次报告：'.$path);

            return self::FAILURE;
        }

        try {
            $synthesis = $synthesizer->synthesize($batch);
            $batch->update([
                'status' => $failed === 0 ? 'completed' : 'completed_with_errors',
                'new_scenario_count' => $synthesis['new_scenarios'],
                'synthesis_output' => $synthesis['output'],
                'usage' => $synthesis['usage'],
                'finished_at' => now(),
            ]);
            $path = $report->write($batch->fresh());
            $knowledgeBasePath = $report->writeKnowledgeBase();
            $this->newLine();
            $this->info('研究批次完成');
            $this->line(json_encode([
                'batch' => $batchKey,
                'selected' => $selected->count(),
                'completed' => $completed,
                'failed' => $failed,
                'decision_nodes' => $nodeCount,
                'findings' => $synthesis['findings'],
                'new_scenarios' => $synthesis['new_scenarios'],
                'business_questions' => $synthesis['questions'],
                'report' => $path,
                'knowledge_base' => $knowledgeBasePath,
                'usage' => $synthesis['usage'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $batch->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                'finished_at' => now(),
            ]);
            $this->error('知识归纳失败：'.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
