<?php

namespace App\Services\N8n;

use App\Models\AiWorkflowReplayRun;
use App\Services\ProductQuestions\ProductQuestionReplayReviewService;
use App\Services\TicketWork\TicketReplayReviewService;
use RuntimeException;

class WorkflowReplayReviewServiceRegistry
{
    public function __construct(
        private readonly ProductQuestionReplayReviewService $productQuestions,
        private readonly TicketReplayReviewService $tickets,
    ) {}

    public function forRun(AiWorkflowReplayRun $run): ProductQuestionReplayReviewService|TicketReplayReviewService
    {
        $domain = $run->workflow()->value('domain');

        return match ($domain) {
            'product_questions' => $this->productQuestions,
            'tickets' => $this->tickets,
            default => throw new RuntimeException("Replay review is not configured for workflow domain: {$domain}"),
        };
    }
}
