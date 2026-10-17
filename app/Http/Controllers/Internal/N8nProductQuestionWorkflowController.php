<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\CommerceSite;
use App\Models\N8nWorkflowExecution;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnalysis;
use App\Models\ProductQuestionDraft;
use App\Services\ProductQuestions\ProductQuestionAnalyzer;
use App\Services\ProductQuestions\ProductQuestionDraftGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class N8nProductQuestionWorkflowController extends Controller
{
    public function leaseAnalysis(Request $request): JsonResponse
    {
        $validated = $this->validateLease($request, true);
        $questions = $this->questions($validated, false)
            ->with('analysis')
            ->get()
            ->reject(fn (ProductQuestion $question): bool => $question->analysis !== null
                && $question->analysis->review_status !== 'candidate')
            ->take($validated['limit'])
            ->values();

        return response()->json([
            'workflow_key' => 'analysis/qa-analysis-v1',
            'count' => $questions->count(),
            'data' => $questions->map(fn (ProductQuestion $question): array => $this->workItem($question, $validated['site_key']))->all(),
        ]);
    }

    public function leaseDrafts(Request $request): JsonResponse
    {
        $validated = $this->validateLease($request, false);
        $questions = $this->questions($validated, true)
            ->limit($validated['limit'])
            ->get();

        return response()->json([
            'workflow_key' => 'work/qa-draft-v1',
            'count' => $questions->count(),
            'data' => $questions->map(fn (ProductQuestion $question): array => $this->workItem($question, $validated['site_key']))->all(),
        ]);
    }

    public function analyze(
        Request $request,
        ProductQuestion $question,
        ProductQuestionAnalyzer $analyzer,
    ): JsonResponse {
        $validated = $this->validateExecution($request, 'analysis/qa-analysis-v1');
        if ($response = $this->completedExecution($question, $validated)) {
            return $response;
        }
        abort_unless($question->remote_present, 409, 'Question is no longer present on the source site.');
        abort_unless($analyzer->isConfigured(), 503, 'MiniMax is not configured.');

        return $this->execute(
            $question,
            $validated,
            function () use ($analyzer, $question, $validated): array {
                $result = $analyzer->analyze($question, (bool) ($validated['force'] ?? false));

                return [
                    'result' => $result,
                    'result_type' => ProductQuestionAnalysis::class,
                    'result_id' => $result['analysis_id'],
                ];
            },
        );
    }

    public function draft(
        Request $request,
        ProductQuestion $question,
        ProductQuestionDraftGenerator $generator,
    ): JsonResponse {
        $validated = $this->validateExecution($request, 'work/qa-draft-v1');
        if ($response = $this->completedExecution($question, $validated)) {
            return $response;
        }
        abort_unless($question->remote_present, 409, 'Question is no longer present on the source site.');
        abort_unless($question->reply_status === 'unanswered', 409, 'Only unanswered questions can receive a draft.');
        abort_unless($generator->isConfigured(), 503, 'MiniMax is not configured.');

        return $this->execute(
            $question,
            $validated,
            function () use ($generator, $question): array {
                $draft = $generator->generate($question);

                return [
                    'result' => [
                        'status' => $draft->status,
                        'draft_id' => $draft->id,
                        'intents' => $draft->intents,
                        'missing_facts' => $draft->missing_facts,
                        'safety_flags' => $draft->safety_flags,
                        'recommended_visible' => $draft->recommended_visible,
                    ],
                    'result_type' => ProductQuestionDraft::class,
                    'result_id' => $draft->id,
                ];
            },
        );
    }

    /** @return array<string, mixed> */
    private function validateLease(Request $request, bool $allowReplyStatus): array
    {
        $rules = [
            'site_key' => ['required', 'string', 'max:100', Rule::exists('commerce_sites', 'site_key')->where('enabled', true)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'question_ids' => ['sometimes', 'array', 'max:50'],
            'question_ids.*' => ['integer', 'min:1'],
        ];
        if ($allowReplyStatus) {
            $rules['reply_status'] = ['sometimes', Rule::in(['all', 'answered', 'unanswered'])];
        }
        $validated = $request->validate($rules);
        $validated['limit'] = (int) ($validated['limit'] ?? 10);
        $validated['reply_status'] = $allowReplyStatus ? ($validated['reply_status'] ?? 'unanswered') : 'unanswered';

        return $validated;
    }

    /** @return Builder<ProductQuestion> */
    private function questions(array $validated, bool $draft): Builder
    {
        $siteId = CommerceSite::query()->where('site_key', $validated['site_key'])->value('id');
        $query = ProductQuestion::query()
            ->where('commerce_site_id', $siteId)
            ->where('remote_present', true)
            ->orderByDesc('remote_created_at')
            ->orderByDesc('id');

        if (($validated['question_ids'] ?? []) !== []) {
            $query->whereIn('external_id', $validated['question_ids']);
        } elseif ($validated['reply_status'] !== 'all') {
            $query->where('reply_status', $validated['reply_status']);
        }
        if ($draft) {
            $query->where('reply_status', 'unanswered');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function validateExecution(Request $request, string $workflowKey): array
    {
        return $request->validate([
            'workflow_key' => ['required', Rule::in([$workflowKey])],
            'workflow_version' => ['required', 'string', 'max:100'],
            'execution_id' => ['required', 'string', 'max:255'],
            'force' => ['sometimes', 'boolean'],
        ]);
    }

    private function execute(ProductQuestion $question, array $input, callable $callback): JsonResponse
    {
        $execution = N8nWorkflowExecution::query()->firstOrCreate(
            [
                'workflow_key' => $input['workflow_key'],
                'workflow_version' => $input['workflow_version'],
                'execution_id' => $input['execution_id'],
                'product_question_id' => $question->id,
            ],
            [
                'status' => 'running',
                'started_at' => now(),
            ],
        );

        if (! $execution->wasRecentlyCreated && $execution->status === 'completed') {
            return response()->json([
                'execution' => $this->execution($execution),
                'result' => $execution->result,
                'reused' => true,
            ]);
        }
        if (! $execution->wasRecentlyCreated && $execution->status === 'running') {
            throw new ConflictHttpException('This workflow item is already running.');
        }

        $execution->update([
            'status' => 'running',
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
        ]);

        try {
            $outcome = $callback();
            $execution->update([
                'status' => 'completed',
                'result_type' => $outcome['result_type'],
                'result_id' => $outcome['result_id'],
                'result' => $outcome['result'],
                'finished_at' => now(),
            ]);

            return response()->json([
                'execution' => $this->execution($execution->fresh()),
                'result' => $outcome['result'],
                'reused' => false,
            ]);
        } catch (Throwable $exception) {
            $execution->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function completedExecution(ProductQuestion $question, array $input): ?JsonResponse
    {
        $execution = N8nWorkflowExecution::query()->where([
            'workflow_key' => $input['workflow_key'],
            'workflow_version' => $input['workflow_version'],
            'execution_id' => $input['execution_id'],
            'product_question_id' => $question->id,
            'status' => 'completed',
        ])->first();

        return $execution === null ? null : response()->json([
            'execution' => $this->execution($execution),
            'result' => $execution->result,
            'reused' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function workItem(ProductQuestion $question, string $siteKey): array
    {
        return [
            'question_id' => $question->id,
            'external_question_id' => $question->external_id,
            'site_key' => $siteKey,
            'reply_status' => $question->reply_status,
        ];
    }

    /** @return array<string, mixed> */
    private function execution(N8nWorkflowExecution $execution): array
    {
        return [
            'id' => $execution->id,
            'workflow_key' => $execution->workflow_key,
            'workflow_version' => $execution->workflow_version,
            'execution_id' => $execution->execution_id,
            'status' => $execution->status,
            'result_type' => $execution->result_type,
            'result_id' => $execution->result_id,
        ];
    }
}
