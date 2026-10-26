<?php

namespace App\Console\Commands;

use App\Models\WorkItem;
use App\Services\N8n\N8nTicketDraftWorkflowClient;
use App\Services\TicketWork\TicketWorkItemManager;
use Illuminate\Console\Command;
use Throwable;

class ReapTicketDraftWorkItems extends Command
{
    protected $signature = 'tickets:reap-draft-work-items
                            {--queued-minutes=2 : Retry queued WorkItems older than this}
                            {--running-minutes=10 : Recover expired running WorkItems older than this}';

    protected $description = 'Retry undelivered Ticket Draft WorkItems and recover expired n8n leases';

    public function handle(N8nTicketDraftWorkflowClient $n8n): int
    {
        $queuedMinutes = max(1, (int) $this->option('queued-minutes'));
        $runningMinutes = max(1, (int) $this->option('running-minutes'));
        $retried = 0;
        $failed = 0;

        WorkItem::query()
            ->where('work_type', TicketWorkItemManager::WORK_TYPE)
            ->where('status', 'running')
            ->where(function ($query) use ($runningMinutes): void {
                $query->where('lease_expires_at', '<', now())
                    ->orWhere(fn ($query) => $query->whereNull('lease_expires_at')->where('updated_at', '<', now()->subMinutes($runningMinutes)));
            })
            ->orderBy('id')
            ->each(function (WorkItem $item) use ($n8n, &$retried, &$failed): void {
                if ($item->attempt_count >= $item->max_attempts || $item->deadline_at?->isPast()) {
                    $this->failItem($item, 'Ticket Draft exhausted its attempts or deadline after an expired n8n lease.');
                    $failed++;

                    return;
                }
                $item->update([
                    'status' => 'queued',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'execution_id' => null,
                    'error_message' => 'Previous n8n lease expired; queued for retry.',
                ]);
                try {
                    $n8n->trigger($item->fresh());
                    $item->update(['error_message' => null]);
                    $retried++;
                } catch (Throwable $exception) {
                    $item->update(['error_message' => mb_substr($exception->getMessage(), 0, 5000)]);
                }
            });

        WorkItem::query()
            ->where('work_type', TicketWorkItemManager::WORK_TYPE)
            ->where('status', 'queued')
            ->where('updated_at', '<', now()->subMinutes($queuedMinutes))
            ->orderBy('id')
            ->each(function (WorkItem $item) use ($n8n, &$retried, &$failed): void {
                if ($item->deadline_at?->isPast()) {
                    $this->failItem($item, 'Ticket Draft could not be delivered before its deadline.');
                    $failed++;

                    return;
                }
                try {
                    $n8n->trigger($item);
                    $item->update(['error_message' => null]);
                    $item->touch();
                    $retried++;
                } catch (Throwable $exception) {
                    $item->update(['error_message' => mb_substr($exception->getMessage(), 0, 5000)]);
                }
            });

        $this->info("retried={$retried} failed={$failed}");

        return self::SUCCESS;
    }

    private function failItem(WorkItem $item, string $message): void
    {
        $item->update([
            'status' => 'failed',
            'error_message' => $message,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'finished_at' => now(),
        ]);
    }
}
