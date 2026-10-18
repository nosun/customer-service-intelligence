<?php

namespace App\Services\Work;

use App\Models\ProductQuestionDraftRequest;
use App\Models\WorkItem;
use Illuminate\Support\Str;

class ProductQuestionWorkItemManager
{
    public function __construct(private readonly CustomerCaseManager $cases) {}

    public function attach(ProductQuestionDraftRequest $request): WorkItem
    {
        if ($request->workItem !== null) {
            return $request->workItem;
        }
        $request->loadMissing('question');
        $case = $this->cases->forProductQuestion($request->question);
        $item = WorkItem::create([
            'work_item_key' => (string) Str::uuid(),
            'customer_case_id' => $case->id,
            'work_type' => 'product_question_draft',
            'status' => $request->status,
            'workflow_key' => $request->workflow_key,
            'workflow_version' => $request->workflow_version,
            'input_schema_version' => '1.0.0',
            'output_schema_version' => '1.0.0',
            'input_hash' => $request->input_hash,
            'input_snapshot' => $request->input_snapshot,
            'attempt_count' => (int) ($request->attempt_count ?? 0),
            'execution_id' => $request->n8n_execution_id,
            'error_message' => $request->error_message,
            'started_at' => $request->started_at,
            'finished_at' => $request->finished_at,
            'deadline_at' => $request->created_at?->copy()->addMinutes(10),
            'result' => $request->product_question_draft_id === null ? null : [
                'type' => 'product_question_draft',
                'id' => $request->product_question_draft_id,
            ],
        ]);
        $request->update(['work_item_id' => $item->id]);

        return $item;
    }

    public function sync(ProductQuestionDraftRequest $request): void
    {
        if ($request->work_item_id === null) {
            return;
        }
        $request->workItem()->update([
            'status' => $request->status,
            'input_hash' => $request->input_hash,
            'input_snapshot' => $request->input_snapshot,
            'attempt_count' => (int) ($request->attempt_count ?? 0),
            'execution_id' => $request->n8n_execution_id,
            'result' => $request->product_question_draft_id === null ? null : [
                'type' => 'product_question_draft',
                'id' => $request->product_question_draft_id,
            ],
            'error_message' => $request->error_message,
            'started_at' => $request->started_at,
            'finished_at' => $request->finished_at,
            'lease_owner' => $request->status === 'running' ? $request->n8n_execution_id : null,
            'lease_expires_at' => $request->status === 'running' ? now()->addMinutes(10) : null,
        ]);
    }
}
