<?php

namespace App\Services\N8n;

use App\Models\ProductQuestionDraftRequest;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class N8nDraftWorkflowClient
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.n8n.base_url')) !== ''
            && trim((string) config('services.n8n.webhook_token')) !== '';
    }

    public function trigger(ProductQuestionDraftRequest $request): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('n8n Draft workflow is not configured.');
        }

        $request->loadMissing('question.site');
        $response = $this->request()->post(
            trim((string) config('services.n8n.draft_webhook_path'), '/'),
            [
                'request_id' => $request->request_id,
                'question_id' => $request->product_question_id,
                'site_key' => $request->question->site->site_key,
                'workflow_key' => $request->workflow_key,
                'workflow_version' => $request->workflow_version,
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
