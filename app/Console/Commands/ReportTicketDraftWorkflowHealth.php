<?php

namespace App\Console\Commands;

use App\Services\TicketWork\TicketDraftWorkflowHealth;
use Illuminate\Console\Command;

class ReportTicketDraftWorkflowHealth extends Command
{
    protected $signature = 'tickets:draft-workflow-health
                            {--hours=24 : Reporting window}
                            {--fail-on-unhealthy : Return failure when stuck or failed work exists}';

    protected $description = 'Report Ticket Draft WorkItem status, latency, blocking, and human review health';

    public function handle(TicketDraftWorkflowHealth $health): int
    {
        $snapshot = $health->snapshot(max(1, (int) $this->option('hours')));
        $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $this->option('fail-on-unhealthy') && ! $snapshot['healthy'] ? self::FAILURE : self::SUCCESS;
    }
}
