<?php

namespace App\Services\Work;

use App\Models\ActionProposal;
use App\Models\CustomerCase;
use App\Models\WorkItem;
use Illuminate\Support\Str;
use RuntimeException;

class ActionProposalService
{
    public function propose(
        CustomerCase $case,
        string $actionType,
        array $parameters,
        array $evidence = [],
        ?WorkItem $workItem = null,
        ?int $proposedBy = null,
    ): ActionProposal {
        $idempotencyKey = hash('sha256', json_encode([
            $case->case_key,
            $actionType,
            $parameters,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return ActionProposal::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'proposal_key' => (string) Str::uuid(),
                'customer_case_id' => $case->id,
                'work_item_id' => $workItem?->id,
                'action_type' => $actionType,
                'risk_level' => 'high',
                'status' => 'proposed',
                'parameters' => $parameters,
                'evidence' => $evidence,
                'proposed_by' => $proposedBy,
            ],
        );
    }

    public function approve(ActionProposal $proposal, int $reviewerId, ?string $notes = null): ActionProposal
    {
        if ($proposal->status !== 'proposed') {
            throw new RuntimeException('Only proposed actions can be approved.');
        }
        $proposal->update([
            'status' => 'approved',
            'reviewed_by' => $reviewerId,
            'review_notes' => $notes,
            'reviewed_at' => now(),
        ]);

        return $proposal->refresh();
    }

    public function reject(ActionProposal $proposal, int $reviewerId, string $notes): ActionProposal
    {
        if ($proposal->status !== 'proposed') {
            throw new RuntimeException('Only proposed actions can be rejected.');
        }
        if (trim($notes) === '') {
            throw new RuntimeException('A rejection reason is required.');
        }
        $proposal->update([
            'status' => 'rejected',
            'reviewed_by' => $reviewerId,
            'review_notes' => trim($notes),
            'reviewed_at' => now(),
        ]);

        return $proposal->refresh();
    }

    public function markExecuting(ActionProposal $proposal): ActionProposal
    {
        if ($proposal->status !== 'approved') {
            throw new RuntimeException('An action must be approved before execution.');
        }
        $proposal->update(['status' => 'executing']);

        return $proposal->refresh();
    }

    public function recordResult(ActionProposal $proposal, bool $succeeded, array $result): ActionProposal
    {
        if (! in_array($proposal->status, ['approved', 'executing'], true)) {
            throw new RuntimeException('Only approved actions can record an execution result.');
        }
        $proposal->update([
            'status' => $succeeded ? 'succeeded' : 'failed',
            'execution_result' => $result,
            'executed_at' => now(),
        ]);

        return $proposal->refresh();
    }
}
