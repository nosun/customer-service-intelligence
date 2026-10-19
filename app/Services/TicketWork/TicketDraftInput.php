<?php

namespace App\Services\TicketWork;

use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\KnowledgeArticle;

class TicketDraftInput
{
    public function __construct(private readonly TicketRuntimeContext $context) {}

    /** @return array{hash:string,snapshot:array<string,mixed>,trigger:ConversationEvent} */
    public function capture(
        Conversation $conversation,
        int $requestedBy,
        string $workflowKey,
        string $workflowVersion,
        ?ConversationEvent $trigger = null,
    ): array {
        $trigger ??= $this->context->triggerEvent($conversation);
        $events = $this->context->stageEvents($conversation, $trigger);
        $policyFingerprint = KnowledgeArticle::query()
            ->where('status', 'verified')
            ->where('key', 'not like', 'qa_%')
            ->where(fn ($query) => $query->whereNull('site_key')->orWhere('site_key', $conversation->site_key))
            ->orderBy('id')
            ->get(['id', 'version', 'updated_at'])
            ->map(fn (KnowledgeArticle $article): array => [
                'id' => $article->id,
                'version' => $article->version,
                'updated_at' => $article->updated_at?->toIso8601String(),
            ])->all();
        $snapshot = [
            'conversation_id' => $conversation->id,
            'trigger_event_id' => $trigger->id,
            'trigger_content_hash' => $trigger->content_hash,
            'stage_event_hashes' => $events->pluck('content_hash')->values()->all(),
            'site_key' => $conversation->site_key,
            'status' => $conversation->status,
            'silo' => $conversation->silo,
            'analysis_updated_at' => $conversation->analysis?->updated_at?->toIso8601String(),
            'policy_fingerprint' => $policyFingerprint,
            'workflow_key' => $workflowKey,
            'workflow_version' => $workflowVersion,
            'requested_by' => $requestedBy,
        ];

        return [
            'hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'snapshot' => $snapshot,
            'trigger' => $trigger,
        ];
    }
}
