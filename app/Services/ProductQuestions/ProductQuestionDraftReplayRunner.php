<?php

namespace App\Services\ProductQuestions;

use App\Models\CommerceProduct;
use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionDraftRequest;
use App\Services\N8n\N8nDraftWorkflowClient;
use App\Services\N8n\WorkflowRegistry;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProductQuestionDraftReplayRunner
{
    public function __construct(
        private readonly N8nDraftWorkflowClient $n8n,
        private readonly ProductQuestionDraftInput $input,
        private readonly WorkflowRegistry $workflows,
    ) {}

    /** @param list<array<string,mixed>> $cases
     * @param  callable(string):void|null  $progress
     * @return list<array<string,mixed>>
     */
    public function run(
        CommerceSite $site,
        CommerceProduct $product,
        array $cases,
        string $workflowKey,
        string $workflowVersion,
        int $batchSize = 5,
        int $timeoutSeconds = 900,
        int $pollMilliseconds = 2000,
        bool $cleanup = false,
        ?callable $progress = null,
    ): array {
        if (! $this->n8n->isConfigured()) {
            throw new RuntimeException('n8n Draft workflow is not configured.');
        }
        $workflow = $this->workflows->runnable($workflowKey, $workflowVersion);
        $runId = (string) Str::uuid();
        $predictions = [];

        try {
            foreach (array_chunk($cases, max(1, $batchSize)) as $batch) {
                $requests = [];
                foreach ($batch as $case) {
                    $question = $this->question($site, $product, $case, $runId);
                    $snapshot = $this->input->capture(
                        $question,
                        $workflowKey,
                        $workflowVersion,
                        $workflow->prompt_version ?: N8nProductQuestionDraftService::PROMPT_VERSION,
                    );
                    $request = ProductQuestionDraftRequest::create([
                        'request_id' => (string) Str::uuid(),
                        'product_question_id' => $question->id,
                        'workflow_key' => $workflowKey,
                        'workflow_version' => $workflowVersion,
                        'input_hash' => $snapshot['hash'],
                        'input_snapshot' => $snapshot['snapshot'],
                        'force_regeneration' => true,
                        'status' => 'queued',
                    ]);
                    try {
                        $this->n8n->trigger($request);
                    } catch (Throwable $exception) {
                        $request->update([
                            'status' => 'failed',
                            'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                            'finished_at' => now(),
                        ]);
                    }
                    $requests[(string) $case['id']] = $request;
                    if ($progress !== null) {
                        $progress("triggered={$case['id']} request={$request->request_id}");
                    }
                }

                $deadline = microtime(true) + max(1, $timeoutSeconds);
                do {
                    $pending = 0;
                    foreach ($requests as $request) {
                        $request->refresh();
                        $pending += in_array($request->status, ['queued', 'running'], true) ? 1 : 0;
                    }
                    if ($pending === 0) {
                        break;
                    }
                    usleep(max(10, $pollMilliseconds) * 1000);
                } while (microtime(true) < $deadline);

                foreach ($requests as $caseId => $request) {
                    $request->refresh()->load('draft');
                    $timedOut = in_array($request->status, ['queued', 'running'], true);
                    if ($timedOut) {
                        $request->update([
                            'status' => 'failed',
                            'error_message' => 'Draft replay runner timed out waiting for n8n.',
                            'finished_at' => now(),
                        ]);
                        $request->refresh();
                    }
                    $draft = $request->draft;
                    $prediction = [
                        'id' => $caseId,
                        'status' => $timedOut ? 'timed_out' : ($draft?->status ?? $request->status),
                        'intents' => $draft?->intents ?? [],
                        'answer_en' => $draft?->answer_en,
                        'answer_localized' => $draft?->answer_localized,
                        'knowledge_article_ids' => $draft?->knowledge_article_ids ?? [],
                        'facts' => $draft?->facts ?? [],
                        'fact_sources' => $draft?->fact_sources ?? [],
                        'missing_facts' => $draft?->missing_facts ?? [],
                        'safety_flags' => $draft?->safety_flags ?? [],
                        'recommended_visible' => $draft?->recommended_visible,
                        'duration_ms' => $this->duration($request),
                        'cost' => data_get($draft?->usage, 'cost', 0),
                        'usage' => $draft?->usage,
                        'request_id' => $request->request_id,
                        'execution_id' => $request->n8n_execution_id,
                        'draft_id' => $request->product_question_draft_id,
                        'error_message' => $request->error_message,
                    ];
                    $predictions[] = $prediction;
                    $request->question()->update(['remote_present' => false]);
                    if ($progress !== null) {
                        $progress("completed={$caseId} status={$prediction['status']}");
                    }
                }
            }
        } finally {
            $questions = ProductQuestion::query()
                ->where('commerce_site_id', $site->id)
                ->where('name', '__CSI_N8N_REPLAY__')
                ->where('email', $runId.'@replay.invalid');
            $questions->update(['remote_present' => false]);
            if ($cleanup) {
                $questions->delete();
            }
        }

        return $predictions;
    }

    /** @param array<string,mixed> $case */
    private function question(CommerceSite $site, CommerceProduct $product, array $case, string $runId): ProductQuestion
    {
        return ProductQuestion::create([
            'commerce_site_id' => $site->id,
            'external_id' => random_int(7_000_000_000_000_000_000, 8_000_000_000_000_000_000),
            'sn' => $product->sn,
            'product' => $product->product,
            'name' => '__CSI_N8N_REPLAY__',
            'email' => $runId.'@replay.invalid',
            'type' => 1,
            'type_text' => 'Replay question',
            'question' => (string) $case['question'],
            'reply_status' => 'unanswered',
            'needs_reply' => true,
            'visible' => false,
            'notice_status' => 0,
            'notice_status_text' => 'Replay only',
            'images' => [],
            'weight' => 0,
            'helpful' => 0,
            'raw_payload' => [
                '_replay' => [
                    'run_id' => $runId,
                    'case_id' => $case['id'],
                    'fixture' => (array) ($case['fixture'] ?? []),
                ],
            ],
            'remote_present' => true,
            'remote_created_at' => now(),
            'remote_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }

    private function duration(ProductQuestionDraftRequest $request): int
    {
        $start = $request->started_at ?? $request->created_at;
        $finish = $request->finished_at ?? now();

        return max(0, (int) $start->diffInMilliseconds($finish));
    }
}
