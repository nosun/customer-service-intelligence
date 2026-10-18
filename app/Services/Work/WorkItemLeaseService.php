<?php

namespace App\Services\Work;

use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WorkItemLeaseService
{
    public function lease(WorkItem $item, string $owner, int $seconds = 600): WorkItem
    {
        return DB::transaction(function () use ($item, $owner, $seconds): WorkItem {
            $locked = WorkItem::query()->lockForUpdate()->findOrFail($item->id);
            $leaseExpired = $locked->lease_expires_at === null || $locked->lease_expires_at->isPast();
            if ($locked->status === 'running' && ! $leaseExpired && $locked->lease_owner !== $owner) {
                throw new RuntimeException('Work item is leased by another worker.');
            }
            if (! in_array($locked->status, ['queued', 'running'], true)) {
                throw new RuntimeException('Work item cannot be leased from its current status.');
            }
            if ($locked->attempt_count >= $locked->max_attempts && $locked->lease_owner !== $owner) {
                throw new RuntimeException('Work item has exhausted its attempts.');
            }
            $newAttempt = $locked->status !== 'running' || $locked->lease_owner !== $owner;
            $locked->update([
                'status' => 'running',
                'lease_owner' => $owner,
                'lease_expires_at' => now()->addSeconds(max(30, $seconds)),
                'attempt_count' => $locked->attempt_count + ($newAttempt ? 1 : 0),
                'started_at' => $locked->started_at ?? now(),
                'error_message' => null,
            ]);

            return $locked->refresh();
        });
    }

    public function complete(WorkItem $item, string $owner, array $result): WorkItem
    {
        return $this->finish($item, $owner, 'completed', $result, null);
    }

    public function fail(WorkItem $item, string $owner, string $message): WorkItem
    {
        return $this->finish($item, $owner, 'failed', null, $message);
    }

    private function finish(
        WorkItem $item,
        string $owner,
        string $status,
        ?array $result,
        ?string $message,
    ): WorkItem {
        return DB::transaction(function () use ($item, $owner, $status, $result, $message): WorkItem {
            $locked = WorkItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($locked->status !== 'running' || $locked->lease_owner !== $owner) {
                throw new RuntimeException('Only the current lease owner can finish a work item.');
            }
            if ($locked->lease_expires_at === null || $locked->lease_expires_at->isPast()) {
                throw new RuntimeException('Work item lease has expired.');
            }
            $locked->update([
                'status' => $status,
                'result' => $result,
                'error_message' => $message === null ? null : mb_substr($message, 0, 5000),
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
