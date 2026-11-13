<?php

namespace App\Services\N8n;

use App\Models\WorkItem;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class N8nMultiDressWorkflowClient
{
    public function trigger(WorkItem $item): void
    {
        if ($item->work_type !== 'multi_dress_first_round' || blank(config('services.n8n.webhook_token'))) {
            throw new RuntimeException('Multi-dress workflow is not configured.');
        }
        Http::baseUrl(rtrim(config('services.n8n.base_url'), '/'))
            ->withHeaders(['X-Workflow-Token' => config('services.n8n.webhook_token')])->asJson()->connectTimeout(2)->timeout(10)
            ->post(config('customer_contacts.workflow_path'), ['work_item_key' => $item->work_item_key,
                'workflow_key' => $item->workflow_key, 'workflow_version' => $item->workflow_version])->throw();
    }
}
