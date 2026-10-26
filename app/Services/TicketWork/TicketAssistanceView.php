<?php

namespace App\Services\TicketWork;

use App\Models\Conversation;
use App\Models\WorkItem;
use Throwable;

/** A presentation of persisted, reviewed workflow output; never invents new conclusions. */
class TicketAssistanceView
{
    public function current(Conversation $ticket, WorkItem $item): bool
    {
        if ($item->workflow_key === \App\Services\Agents\TicketAgentWorkflow::KEY) {
            return app(\App\Services\Agents\TicketAgentWorkflow::class)->current($ticket, $item);
        }
        try {
            $input = app(TicketDraftInput::class)->capture($ticket, (int) data_get($item->input_snapshot, 'requested_by'), $item->workflow_key, $item->workflow_version);

            return hash_equals($item->input_hash, $input['hash']);
        } catch (Throwable) {
            return false;
        }
    }

    public function present(Conversation $ticket, ?WorkItem $item): array
    {
        $result = (array) $item?->result;
        $reply = (array) ($result['reply_plan'] ?? data_get($result, 'runtime.reply_plan', []));
        $plan = (array) ($result['plan'] ?? data_get($result, 'runtime.plan', []));
        $facts = (array) ($result['facts'] ?? data_get($result, 'runtime.facts', data_get($result, 'runtime_audit.facts', [])));
        $gaps = array_values(array_unique(array_merge(
            (array) ($result['missing_facts'] ?? data_get($result, 'runtime.missing_facts', [])),
            (array) ($reply['blocking_reasons'] ?? []),
            ...array_map(fn ($unit) => (array) ($unit['essential_unknowns'] ?? []), (array) ($reply['answer_units'] ?? [])),
        )));
        $status = $item?->status ?? 'pending';
        if ($item !== null && ! $this->current($ticket, $item)) {
            $status = 'stale';
        } elseif ($status === 'completed') {
            $status = match ($result['draft_status'] ?? '') {
                'blocked', 'needs_review' => 'needs_information',
                'no_reply' => 'no_reply',
                default => 'review',
            };
        }

        return [
            'status' => $status,
            'label' => ['pending' => '待分析', 'queued' => '等待分析', 'running' => '调查中', 'stale' => '结果待更新',
                'failed' => '分析失败', 'cancelled' => '已停止', 'needs_information' => '需补充或核实依据',
                'review' => '可审核', 'no_reply' => '建议暂不回复，待确认'][$status] ?? $status,
            'analysis' => ['summary' => data_get($reply, 'case_progress.summary'), 'requests' => $plan['clauses'] ?? [],
                'facts' => $facts, 'progress' => $reply['case_progress'] ?? [], 'gaps' => $gaps],
            'advice' => ['decision' => $reply['response_decision'] ?? [], 'units' => $reply['answer_units'] ?? [],
                'followups' => $reply['internal_followups'] ?? [], 'policies' => $result['knowledge_articles'] ?? [],
                'conflicts' => array_merge((array) data_get($result, 'review.guidance_conflicts', []), (array) data_get($result, 'review.business_conflicts', []))],
            'draft_status' => $result['draft_status'] ?? null,
        ];
    }
}
