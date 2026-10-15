<?php

namespace App\Console\Commands;

use App\Models\KnowledgeResearchBatch;
use App\Services\KnowledgeResearch\KnowledgeResearchReport;
use App\Services\KnowledgeResearch\KnowledgeResearchSynthesizer;
use Illuminate\Console\Command;
use Throwable;

class SynthesizeKnowledgeResearch extends Command
{
    protected $signature = 'helpdesk:research:synthesize {--batch= : Existing research batch key}';

    protected $description = 'Re-run knowledge synthesis for the completed Decision Cases in one research batch';

    public function handle(KnowledgeResearchSynthesizer $synthesizer, KnowledgeResearchReport $report): int
    {
        $batchKey = trim((string) $this->option('batch'));
        if ($batchKey === '') {
            $this->error('必须提供 --batch。');

            return self::INVALID;
        }
        $batch = KnowledgeResearchBatch::where('batch_key', $batchKey)->first();
        if ($batch === null) {
            $this->error("找不到批次 {$batchKey}。");

            return self::INVALID;
        }
        if ($batch->items()->where('status', 'completed')->doesntExist()) {
            $this->error('该批次没有可用于归纳的已完成 Decision Case。');

            return self::FAILURE;
        }

        try {
            $synthesis = $synthesizer->synthesize($batch);
            $batch->update([
                'status' => $batch->failed_count === 0 ? 'completed' : 'completed_with_errors',
                'new_scenario_count' => $synthesis['new_scenarios'],
                'synthesis_output' => $synthesis['output'],
                'usage' => $synthesis['usage'],
                'error_message' => null,
                'finished_at' => now(),
            ]);
            $batchPath = $report->write($batch->fresh());
            $knowledgeBasePath = $report->writeKnowledgeBase();
            $this->info('知识归纳完成。');
            $this->line(json_encode([
                'batch' => $batchKey,
                'findings' => $synthesis['findings'],
                'new_scenarios' => $synthesis['new_scenarios'],
                'business_questions' => $synthesis['questions'],
                'report' => $batchPath,
                'knowledge_base' => $knowledgeBasePath,
                'usage' => $synthesis['usage'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
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
