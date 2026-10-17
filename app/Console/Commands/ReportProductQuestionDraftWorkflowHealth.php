<?php

namespace App\Console\Commands;

use App\Services\ProductQuestions\ProductQuestionDraftWorkflowHealth;
use Illuminate\Console\Command;

class ReportProductQuestionDraftWorkflowHealth extends Command
{
    protected $signature = 'product-questions:draft-workflow-health
                            {--hours=24 : Lookback window}
                            {--fail-on-unhealthy : Return a failure code for stuck queued or running requests}';

    protected $description = 'Report n8n product-question Draft status, latency, blocking, and recent errors';

    public function handle(ProductQuestionDraftWorkflowHealth $health): int
    {
        $snapshot = $health->snapshot(max(1, (int) $this->option('hours')));
        $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $this->option('fail-on-unhealthy') && ! $snapshot['healthy']
            ? self::FAILURE
            : self::SUCCESS;
    }
}
