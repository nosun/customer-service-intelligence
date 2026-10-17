<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ProductQuestionDraftRequest;
use App\Services\AI\MiniMaxClient;
use App\Services\ProductQuestions\N8nProductQuestionDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class N8nProductQuestionDraftController extends Controller
{
    public function start(
        Request $httpRequest,
        string $requestId,
        N8nProductQuestionDraftService $service,
    ): JsonResponse {
        $input = $httpRequest->validate([
            'execution_id' => ['required', 'string', 'max:255'],
            'workflow_key' => ['required', 'string', 'max:255'],
            'workflow_version' => ['required', 'string', 'max:100'],
        ]);
        $request = $this->draftRequest($requestId);
        abort_unless(
            hash_equals($request->workflow_key, $input['workflow_key'])
                && hash_equals($request->workflow_version, $input['workflow_version']),
            409,
            'Workflow identity does not match the Draft request.',
        );

        return response()->json(['context' => $service->start($request, $input['execution_id'])]);
    }

    public function facts(
        Request $httpRequest,
        string $requestId,
        N8nProductQuestionDraftService $service,
    ): JsonResponse {
        $input = $httpRequest->validate(['plan' => ['required', 'array']]);

        return response()->json($service->resolveFacts($this->runningRequest($requestId), $input['plan']));
    }

    public function policies(
        Request $httpRequest,
        string $requestId,
        N8nProductQuestionDraftService $service,
    ): JsonResponse {
        $input = $httpRequest->validate([
            'intents' => ['present', 'array', 'max:4'],
            'intents.*' => ['string', 'max:100'],
        ]);

        return response()->json($service->policies($this->runningRequest($requestId), $input['intents']));
    }

    public function complete(
        Request $httpRequest,
        string $requestId,
        N8nProductQuestionDraftService $service,
    ): JsonResponse {
        $input = $httpRequest->validate([
            'input_hash' => ['sometimes', 'string', 'size:64'],
            'plan' => ['required', 'array'],
            'facts' => ['required', 'array'],
            'fact_sources' => ['present', 'array'],
            'missing_facts' => ['present', 'array'],
            'safety_flags' => ['present', 'array'],
            'recommended_visible' => ['required', 'boolean'],
            'policies' => ['present', 'array'],
            'review' => ['required', 'array'],
            'model' => ['nullable', 'string', 'max:255'],
            'usage' => ['nullable', 'array'],
            'provider_request_id' => ['nullable', 'string', 'max:255'],
            'prompt_hash' => ['nullable', 'string', 'size:64'],
        ]);

        return response()->json($service->complete($this->draftRequest($requestId), $input));
    }

    public function fail(
        Request $httpRequest,
        string $requestId,
        N8nProductQuestionDraftService $service,
    ): JsonResponse {
        $input = $httpRequest->validate(['error_message' => ['required', 'string']]);
        $service->fail($this->draftRequest($requestId), $input['error_message']);

        return response()->json(['status' => 'failed']);
    }

    public function completion(Request $request, MiniMaxClient $client): JsonResponse
    {
        abort_unless($client->isConfigured(), 503, 'MiniMax is not configured.');
        $input = $request->validate([
            'system_prompt' => ['required', 'string', 'max:50000'],
            'user_prompt' => ['required', 'string', 'max:200000'],
        ]);

        return response()->json($client->structuredCompletion($input['system_prompt'], $input['user_prompt']));
    }

    private function draftRequest(string $requestId): ProductQuestionDraftRequest
    {
        return ProductQuestionDraftRequest::query()
            ->with(['question.site', 'question.analysis', 'draft'])
            ->where('request_id', $requestId)
            ->firstOrFail();
    }

    private function runningRequest(string $requestId): ProductQuestionDraftRequest
    {
        return ProductQuestionDraftRequest::query()
            ->with(['question.site', 'question.analysis'])
            ->where('request_id', $requestId)
            ->where('status', 'running')
            ->firstOrFail();
    }
}
