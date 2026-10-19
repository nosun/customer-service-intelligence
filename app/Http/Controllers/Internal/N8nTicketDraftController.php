<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\WorkItem;
use App\Services\TicketWork\N8nTicketDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class N8nTicketDraftController extends Controller
{
    public function start(Request $request, string $workItemKey, N8nTicketDraftService $service): JsonResponse
    {
        $input = $request->validate([
            'execution_id' => ['required', 'string', 'max:255'],
            'workflow_key' => ['required', 'string', 'max:255'],
            'workflow_version' => ['required', 'string', 'max:100'],
        ]);
        $item = $this->item($workItemKey);
        abort_unless(
            hash_equals((string) $item->workflow_key, $input['workflow_key'])
                && hash_equals((string) $item->workflow_version, $input['workflow_version']),
            409,
            'Workflow identity does not match the Ticket WorkItem.',
        );

        return response()->json(['context' => $service->start($item, $input['execution_id'])]);
    }

    public function facts(Request $request, string $workItemKey, N8nTicketDraftService $service): JsonResponse
    {
        $input = $request->validate([
            'execution_id' => ['required', 'string', 'max:255'],
            'plan' => ['required', 'array'],
        ]);

        return response()->json($service->resolveFacts($this->item($workItemKey), $input['execution_id'], $input['plan']));
    }

    public function policies(Request $request, string $workItemKey, N8nTicketDraftService $service): JsonResponse
    {
        $input = $request->validate([
            'execution_id' => ['required', 'string', 'max:255'],
            'policy_keys' => ['present', 'array', 'max:8'],
            'policy_keys.*' => ['string', 'max:255'],
        ]);

        return response()->json($service->policies($this->item($workItemKey), $input['execution_id'], $input['policy_keys']));
    }

    public function complete(Request $request, string $workItemKey, N8nTicketDraftService $service): JsonResponse
    {
        $input = $request->validate([
            'execution_id' => ['required', 'string', 'max:255'],
            'review' => ['required', 'array'],
            'review.approved' => ['required', 'boolean'],
            'review.must_block' => ['required', 'boolean'],
            'review.covered_intents' => ['present', 'array'],
            'review.final_answer' => ['nullable', 'string', 'max:100000'],
            'review.answer_en' => ['nullable', 'string', 'max:100000'],
            'review.answer_customer_language' => ['nullable', 'string', 'max:100000'],
            'review.safety_flags' => ['present', 'array'],
        ]);

        return response()->json($service->complete($this->item($workItemKey), $input['execution_id'], $input['review']));
    }

    public function fail(Request $request, string $workItemKey, N8nTicketDraftService $service): JsonResponse
    {
        $input = $request->validate([
            'execution_id' => ['required', 'string', 'max:255'],
            'error_message' => ['required', 'string', 'max:5000'],
        ]);
        $service->fail($this->item($workItemKey), $input['execution_id'], $input['error_message']);

        return response()->json(['status' => 'failed']);
    }

    private function item(string $workItemKey): WorkItem
    {
        return WorkItem::query()
            ->where('work_type', 'ticket_draft')
            ->where('work_item_key', $workItemKey)
            ->firstOrFail();
    }
}
