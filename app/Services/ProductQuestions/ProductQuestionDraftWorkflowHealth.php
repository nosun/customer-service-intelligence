<?php

namespace App\Services\ProductQuestions;

use App\Models\ProductQuestionDraftRequest;
use Carbon\CarbonInterface;

class ProductQuestionDraftWorkflowHealth
{
    /** @return array<string,mixed> */
    public function snapshot(int $hours = 24): array
    {
        $since = now()->subHours(max(1, $hours));
        $requests = ProductQuestionDraftRequest::query()
            ->with('draft:id,status')
            ->where('created_at', '>=', $since)
            ->get();
        $counts = collect(['queued', 'running', 'completed', 'failed', 'stale'])
            ->mapWithKeys(fn (string $status): array => [$status => $requests->where('status', $status)->count()])
            ->all();
        $durations = $requests->filter(fn (ProductQuestionDraftRequest $request): bool => $request->finished_at !== null)
            ->map(fn (ProductQuestionDraftRequest $request): int => $this->duration($request->started_at ?? $request->created_at, $request->finished_at))
            ->sort()->values()->all();
        $completed = (int) $counts['completed'];
        $failed = (int) $counts['failed'];
        $total = $requests->count();
        $stuckQueued = $requests->where('status', 'queued')->filter(fn ($request): bool => $request->created_at->lt(now()->subMinutes(2)))->count();
        $stuckRunning = $requests->where('status', 'running')->filter(fn ($request): bool => ($request->started_at ?? $request->created_at)->lt(now()->subMinutes(10)))->count();

        return [
            'window_hours' => max(1, $hours),
            'window_started_at' => $since->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
            'total' => $total,
            'status_counts' => $counts,
            'completion_rate' => $this->rate($completed, $total),
            'workflow_failure_rate' => $this->rate($failed, $total),
            'stale_rate' => $this->rate((int) $counts['stale'], $total),
            'generated_draft_rate' => $this->rate($requests->where('draft.status', 'generated')->count(), $completed),
            'blocked_draft_rate' => $this->rate($requests->where('draft.status', 'blocked')->count(), $completed),
            'p50_duration_ms' => $this->percentile($durations, 0.50),
            'p95_duration_ms' => $this->percentile($durations, 0.95),
            'stuck_queued' => $stuckQueued,
            'stuck_running' => $stuckRunning,
            'healthy' => $stuckQueued === 0 && $stuckRunning === 0,
            'recent_errors' => $requests->whereIn('status', ['failed', 'stale'])
                ->sortByDesc('id')->take(10)->map(fn (ProductQuestionDraftRequest $request): array => [
                    'request_id' => $request->request_id,
                    'status' => $request->status,
                    'execution_id' => $request->n8n_execution_id,
                    'error_message' => mb_substr((string) $request->error_message, 0, 500),
                ])->values()->all(),
        ];
    }

    private function duration(CarbonInterface $start, CarbonInterface $finish): int
    {
        return max(0, (int) $start->diffInMilliseconds($finish));
    }

    private function rate(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : round($count / $total, 4);
    }

    /** @param list<int> $values */
    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        return (float) $values[(int) ceil(count($values) * $percentile) - 1];
    }
}
