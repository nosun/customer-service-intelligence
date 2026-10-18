<?php

namespace App\Services\Work;

use App\Models\ActionProposal;
use App\Models\CustomerCase;
use App\Models\WorkItem;

class WorkContracts
{
    public const CASE_SNAPSHOT_VERSION = '1.0.0';

    public const WORKER_INPUT_VERSION = '1.0.0';

    public const WORKER_RESULT_VERSION = '1.0.0';

    public const ACTION_PROPOSAL_VERSION = '1.0.0';

    /** @return array<string,mixed> */
    public function caseSnapshot(CustomerCase $case): array
    {
        $case->loadMissing('sources');

        return [
            'schema_version' => self::CASE_SNAPSHOT_VERSION,
            'case_key' => $case->case_key,
            'site_key' => $case->site_key,
            'case_type' => $case->case_type,
            'status' => $case->status,
            'risk_level' => $case->risk_level,
            'priority' => $case->priority,
            'queue' => $case->queue,
            'sla_due_at' => $case->sla_due_at?->toIso8601String(),
            'sources' => $case->sources->map(fn ($source): array => [
                'type' => $source->source_type,
                'id' => $source->source_id,
                'customer_identity_hash' => $source->customer_identity_hash,
                'snapshot' => $source->snapshot,
            ])->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    public function workerInput(WorkItem $item): array
    {
        return [
            'schema_version' => self::WORKER_INPUT_VERSION,
            'work_item_key' => $item->work_item_key,
            'work_type' => $item->work_type,
            'workflow' => [
                'key' => $item->workflow_key,
                'version' => $item->workflow_version,
            ],
            'input_hash' => $item->input_hash,
            'input' => $item->input_snapshot,
            'deadline_at' => $item->deadline_at?->toIso8601String(),
        ];
    }

    /** @param list<array<string,mixed>> $facts
     * @param  list<array<string,mixed>>  $actions
     * @return array<string,mixed>
     */
    public function workerResult(
        array $facts,
        array $actions = [],
        ?array $draft = null,
        array $missingFacts = [],
        array $safetyFlags = [],
    ): array {
        return [
            'schema_version' => self::WORKER_RESULT_VERSION,
            'facts' => $facts,
            'missing_facts' => array_values($missingFacts),
            'draft' => $draft,
            'action_proposals' => $actions,
            'safety_flags' => array_values($safetyFlags),
        ];
    }

    /** @return array<string,mixed> */
    public function actionProposal(ActionProposal $proposal): array
    {
        return [
            'schema_version' => self::ACTION_PROPOSAL_VERSION,
            'proposal_key' => $proposal->proposal_key,
            'action_type' => $proposal->action_type,
            'risk_level' => $proposal->risk_level,
            'status' => $proposal->status,
            'parameters' => $proposal->parameters,
            'evidence' => $proposal->evidence,
            'idempotency_key' => $proposal->idempotency_key,
        ];
    }
}
