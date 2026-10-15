<?php

namespace App\Console\Commands;

use App\Models\KnowledgeResearchBatch;
use App\Services\KnowledgeResearch\KnowledgeResearchReport;
use Illuminate\Console\Command;

class ReportKnowledgeResearch extends Command
{
    protected $signature = 'helpdesk:research:report
        {--batch= : Also regenerate one batch report with original customer and agent evidence}';

    protected $description = 'Generate the cumulative observed knowledge-base and decision-tree report';

    public function handle(KnowledgeResearchReport $report): int
    {
        $batchKey = trim((string) $this->option('batch'));
        if ($batchKey !== '') {
            $batch = KnowledgeResearchBatch::where('batch_key', $batchKey)->first();
            if ($batch === null) {
                $this->error("找不到研究批次 {$batchKey}。");

                return self::INVALID;
            }
            $this->info('批次报告已生成：'.$report->write($batch));
        }
        $path = $report->writeKnowledgeBase();
        $this->info('知识库候选报告已生成：'.$path);

        return self::SUCCESS;
    }
}
