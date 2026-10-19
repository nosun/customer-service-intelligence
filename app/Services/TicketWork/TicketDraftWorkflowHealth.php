<?php

namespace App\Services\TicketWork;

use App\Models\WorkItem;

class TicketDraftWorkflowHealth
{
    /** @return array<string,mixed> */
    public function snapshot(int $hours = 24): array
    {
        $items = WorkItem::query()
            ->where('work_type', TicketWorkItemManager::WORK_TYPE)
            ->where('created_at', '>=', now()->subHours(max(1, $hours)))
            ->get();
        $total = $items->count();
        $terminal = $items->whereIn('status', ['completed', 'failed', 'stale'])->count();
        $failed = $items->whereIn('status', ['failed', 'stale'])->count();
        $completed = $items->where('status', 'completed');
        $blocked = $completed->filter(fn (WorkItem $item): bool => data_get($item->result, 'draft_status') === 'blocked')->count();
        $reviewed = $completed->filter(fn (WorkItem $item): bool => data_get($item->result, 'human_review.outcome') !== null);
        $accepted = $reviewed->filter(fn (WorkItem $item): bool => in_array(
            data_get($item->result, 'human_review.outcome'),
            ['accepted_as_is', 'edited'],
            true,
        ))->count();
        $durations = $completed->map(function (WorkItem $item): int {
            $start = $item->started_at ?? $item->created_at;
            $finish = $item->finished_at ?? now();

            return max(0, (int) $start->diffInMilliseconds($finish));
        })->sort()->values()->all();
        $stuckQueued = $items->where('status', 'queued')->filter(fn (WorkItem $item): bool => $item->updated_at->lt(now()->subMinutes(2)))->count();
        $stuckRunning = $items->where('status', 'running')->filter(fn (WorkItem $item): bool => $item->lease_expires_at?->isPast() ?? true)->count();

        return [
            'hours' => max(1, $hours),
            'total' => $total,
            'status_counts' => $items->countBy('status')->sortKeys()->all(),
            'failure_rate' => $this->rate($failed, $terminal),
            'blocked_draft_rate' => $this->rate($blocked, $completed->count()),
            'human_review_coverage' => $this->rate($reviewed->count(), $completed->count()),
            'human_acceptance_rate' => $this->rate($accepted, $reviewed->count()),
            'stuck_queued' => $stuckQueued,
            'stuck_running' => $stuckRunning,
            'p50_duration_ms' => $this->percentile($durations, 0.50),
            'p95_duration_ms' => $this->percentile($durations, 0.95),
            'healthy' => $stuckQueued === 0 && $stuckRunning === 0 && $failed === 0,
        ];
    }

    private function rate(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : round($count / $total, 4);
    }

    /** @param list<int> $values */
    private function percentile(array $values, float $percentile): int
    {
        return $values === [] ? 0 : $values[(int) ceil(count($values) * $percentile) - 1];
    }
}
