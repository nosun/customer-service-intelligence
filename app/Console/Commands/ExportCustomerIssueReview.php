<?php

namespace App\Console\Commands;

use App\Services\Analysis\CustomerIssueReviewExporter;
use App\Services\Analysis\RuleAnalyzer;
use Illuminate\Console\Command;

class ExportCustomerIssueReview extends Command
{
    protected $signature = 'gmail:review-export
        {--output= : Destination review CSV path}
        {--summary= : Destination summary JSON path}';

    protected $description = 'Analyze inbound mail and export a redacted issue-review dataset';

    public function handle(RuleAnalyzer $analyzer, CustomerIssueReviewExporter $exporter): int
    {
        $analysis = $analyzer->analyze();
        $result = $exporter->export(
            (string) ($this->option('output') ?: storage_path('app/private/reports/customer-issue-review.csv')),
            (string) ($this->option('summary') ?: storage_path('app/private/reports/customer-issue-summary.json')),
        );

        $this->line(json_encode(
            ['analysis' => $analysis, 'export' => $result],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));

        return self::SUCCESS;
    }
}
