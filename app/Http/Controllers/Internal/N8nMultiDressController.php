<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\WorkItem;
use App\Services\CustomerContacts\MultiDressDraftService;
use Illuminate\Http\Request;

class N8nMultiDressController extends Controller
{
    public function handle(Request $request, string $workItemKey, string $action, MultiDressDraftService $service)
    {
        $data = $request->validate(['execution_id' => 'required|string|max:255',
            'workflow_key' => 'required_if:action,start|string', 'workflow_version' => 'sometimes|string',
            'analysis' => 'sometimes|array', 'review' => 'sometimes|array', 'error_message' => 'sometimes|string|max:1000']);
        $item = WorkItem::where('work_type', 'multi_dress_first_round')->where('work_item_key', $workItemKey)->firstOrFail();
        if ($action === 'start') {
            abort_unless(($data['workflow_key'] ?? '') === $item->workflow_key && ($data['workflow_version'] ?? '') === $item->workflow_version, 409);
        }
        $result = match ($action) {
            'start' => ['context' => $service->start($item, $data['execution_id'])],
            'render' => $service->render($item, $data['execution_id'], $data['analysis'] ?? []),
            'complete' => $service->complete($item, $data['execution_id'], $data['analysis'] ?? [], $data['review'] ?? []),
            'fail' => $service->fail($item, $data['execution_id'], $data['error_message'] ?? 'workflow_failed'),
        };

        return response()->json($result ?? ['status' => 'failed']);
    }
}
