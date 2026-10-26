<?php

namespace App\Services\N8n;

use App\Models\WorkItem;
use App\Services\TicketWork\N8nTicketDraftService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class N8nTicketDraftWorkflowClient
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.n8n.base_url')) !== ''
            && trim((string) config('services.n8n.webhook_token')) !== '';
    }

    public function trigger(WorkItem $item): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('n8n Ticket Draft workflow is not configured.');
        }
        $response = $this->request()->post(
            trim((string) config($item->workflow_key === N8nTicketDraftService::INVESTIGATION_KEY ? 'services.n8n.ticket_investigation_webhook_path' : 'services.n8n.ticket_draft_webhook_path'), '/'),
            [
                'work_item_key' => $item->work_item_key,
                'workflow_key' => $item->workflow_key,
                'workflow_version' => $item->workflow_version,
            ],
        );
        $response->throw();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.n8n.base_url'), '/'))
            ->withHeaders(['X-Workflow-Token' => (string) config('services.n8n.webhook_token')])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(2)
            ->timeout(max(3, (int) config('services.n8n.trigger_timeout', 10)));
    }
}
