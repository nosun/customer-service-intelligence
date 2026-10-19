<?php

namespace App\Services\N8n;

use App\Services\ProductQuestions\ProductQuestionDraftReleaseGate;
use App\Services\TicketWork\TicketDraftReleaseGate;
use RuntimeException;

class WorkflowReleaseGateRegistry
{
    public function __construct(
        private readonly ProductQuestionDraftReleaseGate $productQuestions,
        private readonly TicketDraftReleaseGate $tickets,
    ) {}

    /** @return array{gate:ProductQuestionDraftReleaseGate,suite_path:string} */
    public function resolve(string $workflowKey): array
    {
        return match ($workflowKey) {
            'work/qa-draft-agent-v1' => [
                'gate' => $this->productQuestions,
                'suite_path' => resource_path('data/product-question-draft-replay-v1.json'),
            ],
            'work/ticket-draft-v1' => [
                'gate' => $this->tickets,
                'suite_path' => resource_path('data/ticket-draft-replay-v1.json'),
            ],
            default => throw new RuntimeException("No release gate is registered for workflow: {$workflowKey}"),
        };
    }
}
