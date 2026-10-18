<?php

namespace App\Console\Commands;

use App\Services\ProductQuestions\ProductQuestionReplayReviewService;
use Illuminate\Console\Command;

class ImportProductQuestionDraftReplayReview extends Command
{
    protected $signature = 'product-questions:import-draft-replay-review
                            {predictions : Predictions JSON produced by the real n8n replay runner}
                            {--workflow-key=work/qa-draft-agent-v1}
                            {--workflow-version=1.2.0}
                            {--suite=resources/data/product-question-draft-replay-v1.json}
                            {--annotations= : Existing human review JSON to import}';

    protected $description = 'Import a real Workflow replay into the backend review workbench';

    public function handle(ProductQuestionReplayReviewService $reviews): int
    {
        $run = $reviews->import(
            (string) $this->option('workflow-key'),
            (string) $this->option('workflow-version'),
            $this->path((string) $this->argument('predictions')),
            $this->option('annotations') ? $this->path((string) $this->option('annotations')) : null,
            $this->path((string) $this->option('suite')),
        );
        $this->info("Imported review run {$run->id}: {$run->reviewed_count}/{$run->case_count} reviewed.");
        $this->line('admin='.admin_url('workflow-replay-reviews/'.$run->id));

        return self::SUCCESS;
    }

    private function path(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }
}
