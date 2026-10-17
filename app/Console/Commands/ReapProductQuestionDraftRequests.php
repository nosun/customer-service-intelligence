<?php

namespace App\Console\Commands;

use App\Models\ProductQuestionDraftRequest;
use App\Services\N8n\N8nDraftWorkflowClient;
use Illuminate\Console\Command;
use Throwable;

class ReapProductQuestionDraftRequests extends Command
{
    protected $signature = 'product-questions:reap-draft-requests
                            {--queued-minutes=2 : Retry queued requests older than this}
                            {--running-minutes=10 : Fail running requests older than this}
                            {--max-attempts=3 : Maximum queued delivery attempts}';

    protected $description = 'Retry undelivered n8n Draft requests and fail expired running requests';

    public function handle(N8nDraftWorkflowClient $n8n): int
    {
        $queuedMinutes = max(1, (int) $this->option('queued-minutes'));
        $runningMinutes = max(1, (int) $this->option('running-minutes'));
        $maxAttempts = max(1, (int) $this->option('max-attempts'));
        $retried = 0;
        $failed = 0;

        ProductQuestionDraftRequest::query()
            ->where('status', 'queued')
            ->where('updated_at', '<', now()->subMinutes($queuedMinutes))
            ->orderBy('id')
            ->each(function (ProductQuestionDraftRequest $request) use ($n8n, $maxAttempts, &$retried, &$failed): void {
                if ($request->attempt_count >= $maxAttempts) {
                    $this->failRequest($request, 'n8n Draft request could not be delivered after the maximum attempts.');
                    $failed++;

                    return;
                }

                try {
                    $request->increment('attempt_count');
                    $request->touch();
                    $n8n->trigger($request->fresh());
                    $retried++;
                } catch (Throwable $exception) {
                    $request->update(['error_message' => mb_substr($exception->getMessage(), 0, 5000)]);
                }
            });

        ProductQuestionDraftRequest::query()
            ->where('status', 'running')
            ->where('updated_at', '<', now()->subMinutes($runningMinutes))
            ->orderBy('id')
            ->each(function (ProductQuestionDraftRequest $request) use (&$failed, $runningMinutes): void {
                $this->failRequest($request, "n8n Draft workflow exceeded {$runningMinutes} minutes; inspect the Execution before retrying.");
                $failed++;
            });

        $this->info("retried={$retried} failed={$failed}");

        return self::SUCCESS;
    }

    private function failRequest(ProductQuestionDraftRequest $request, string $message): void
    {
        $request->update([
            'status' => 'failed',
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }
}
