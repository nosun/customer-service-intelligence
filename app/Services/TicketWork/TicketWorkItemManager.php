<?php

namespace App\Services\TicketWork;

use App\Models\Conversation;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketWorkItemManager
{
    public const WORK_TYPE = 'ticket_draft';

    public function __construct(
        private readonly TicketDraftInput $input,
        private readonly TicketRuntimeCaseManager $cases,
    ) {}

    public function create(
        Conversation $conversation,
        int $requestedBy,
        string $workflowKey,
        string $workflowVersion,
        bool $reuseCompleted = false,
    ): WorkItem {
        return DB::transaction(function () use ($conversation, $requestedBy, $workflowKey, $workflowVersion, $reuseCompleted): WorkItem {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $conversation->loadMissing('analysis');
            $captured = $this->input->capture($conversation, $requestedBy, $workflowKey, $workflowVersion);
            $fingerprints = [];
            $active = WorkItem::query()
                ->whereHas('customerCase.sources', fn ($query) => $query
                    ->where('source_type', 'conversation')
                    ->where('source_id', (string) $conversation->id))
                ->where('work_type', self::WORK_TYPE)
                ->whereIn('status', $reuseCompleted ? ['queued', 'running', 'completed', 'failed', 'cancelled'] : ['queued', 'running'])
                ->where('workflow_key', $workflowKey)
                ->where('workflow_version', $workflowVersion)
                ->latest('id')
                ->cursor()
                ->first(function (WorkItem $item) use ($conversation, $workflowKey, $workflowVersion, &$fingerprints): bool {
                    $actor = (int) data_get($item->input_snapshot, 'requested_by');
                    $fingerprints[$actor] ??= $this->input->capture($conversation, $actor, $workflowKey, $workflowVersion)['hash'];

                    return hash_equals($item->input_hash, $fingerprints[$actor]);
                });
            if ($active !== null) {
                return $active;
            }

            $case = $this->cases->forConversation($conversation, $captured['trigger']->id);

            return WorkItem::query()->create([
                'work_item_key' => (string) Str::uuid(),
                'customer_case_id' => $case->id,
                'work_type' => self::WORK_TYPE,
                'status' => 'queued',
                'workflow_key' => $workflowKey,
                'workflow_version' => $workflowVersion,
                'input_schema_version' => '1.0.0',
                'output_schema_version' => '1.0.0',
                'input_hash' => $captured['hash'],
                'input_snapshot' => $captured['snapshot'],
                'max_attempts' => 3,
                'deadline_at' => now()->addMinutes($workflowKey === N8nTicketDraftService::INVESTIGATION_KEY ? 30 : 10),
            ]);
        });
    }
}
