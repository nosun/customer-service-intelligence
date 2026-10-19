<?php

namespace App\Console\Commands;

use App\Services\TicketWork\TicketReplayReviewService;
use Illuminate\Console\Command;

class ImportTicketDraftReplayReview extends Command
{
    protected $signature = 'tickets:import-draft-replay-review
                            {predictions : Predictions JSON produced by the real n8n replay runner}
                            {--workflow-key=work/ticket-draft-v1}
                            {--workflow-version=1.0.0}
                            {--suite=resources/data/ticket-draft-replay-v1.json}
                            {--annotations= : Existing human review JSON to import}';

    protected $description = 'Import a real Ticket Workflow replay into the backend review workbench';

    public function handle(TicketReplayReviewService $reviews): int
    {
        $run = $reviews->import(
            (string) $this->option('workflow-key'),
            (string) $this->option('workflow-version'),
            $this->path((string) $this->argument('predictions')),
            $this->option('annotations') ? $this->path((string) $this->option('annotations')) : null,
            $this->path((string) $this->option('suite')),
        );
        $this->info("Imported Ticket review run {$run->id}: {$run->reviewed_count}/{$run->case_count} reviewed.");
        $this->line('admin='.admin_url('workflow-replay-reviews/'.$run->id));

        return self::SUCCESS;
    }

    private function path(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }
}
