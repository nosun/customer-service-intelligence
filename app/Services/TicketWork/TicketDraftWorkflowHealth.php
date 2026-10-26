<?php

namespace App\Services\TicketWork;

use App\Models\WorkItem;
use Illuminate\Support\Collection;

class TicketDraftWorkflowHealth
{
    /** @return array<string,mixed> */
    public function snapshot(int $hours = 24): array
    {
        $all = WorkItem::query()
            ->whereIn('work_type', [TicketWorkItemManager::WORK_TYPE, 'ticket_agent'])
            ->where(fn ($query) => $query->where('created_at', '>=', now()->subHours(max(1, $hours)))
                ->orWhereIn('status', ['queued', 'running']))
            ->get();
        $snapshot = $this->summarize($all, $hours);
        $snapshot['hours'] = max(1, $hours);
        $snapshot['automatic_dispatch_enabled'] = (bool) config('ticket-assistance.enabled');
        $snapshot['workflows'] = $all->groupBy(fn (WorkItem $item) => $item->workflow_key.'@'.$item->workflow_version)
            ->map(fn ($items) => [
                'workflow_key' => $items->first()->workflow_key,
                'workflow_version' => $items->first()->workflow_version,
                ...$this->summarize($items, $hours),
            ])->values()->all();

        return $snapshot;
    }

    private function summarize(Collection $all, int $hours): array
    {
        $items = $all->filter(fn (WorkItem $item) => $item->created_at->gte(now()->subHours(max(1, $hours))));
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
        $active = $all->whereIn('status', ['queued', 'running']);
        $stuck = $active->filter(function (WorkItem $item): bool {
            if ($item->deadline_at?->lte(now())) {
                return true;
            }
            if ($item->work_type === 'ticket_agent') {
                return $item->deadline_at === null && $item->updated_at->lt(now()->subSeconds((int) config('ticket-agent.timeout') + 120));
            }

            return $item->status === 'queued'
                ? $item->updated_at->lt(now()->subMinutes(2))
                : ($item->lease_expires_at?->isPast() ?? $item->updated_at->lt(now()->subMinutes(10)));
        });
        $stuckQueued = $stuck->where('status', 'queued')->count();
        $stuckRunning = $stuck->where('status', 'running')->count();
        $healthy = $stuck->isEmpty() && $failed === 0;
        $technicalStatus = ! $healthy ? 'unhealthy' : (($total > 0 || $active->isNotEmpty()) ? 'healthy'
            : (config('ticket-assistance.enabled') ? 'no_data' : 'disabled'));

        return [
            'technical_status' => $technicalStatus,
            'business_status' => $completed->isEmpty() ? 'no_data' : ($reviewed->isEmpty() ? 'awaiting_review' : 'reviewed'),
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
            'healthy' => $healthy,
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
