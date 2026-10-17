<?php

namespace App\Console\Commands;

use App\Services\ProductQuestions\ProductQuestionDraftFeedbackReport;
use Illuminate\Console\Command;

class ReportProductQuestionDraftFeedback extends Command
{
    protected $signature = 'product-questions:draft-feedback-report {--days=30 : Lookback window}';

    protected $description = 'Summarize human edits and adoption of product-question Drafts';

    public function handle(ProductQuestionDraftFeedbackReport $report): int
    {
        $this->line(json_encode(
            $report->summarize(max(1, (int) $this->option('days'))),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
