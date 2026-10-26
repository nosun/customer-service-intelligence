<?php

namespace App\Services\Agents;

use App\Models\Conversation;
use App\Services\Knowledge\KnowledgeResolver;
use App\Services\TicketWork\TicketDraftInput;
use App\Services\TicketWork\TicketFactResolver;
use App\Services\TicketWork\TicketPolicyRepository;
use App\Services\TicketWork\TicketRuntimeContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use RuntimeException;

class TicketInvestigationInput
{
    public const WORKFLOW_KEY = 'experimental/ticket-logistics-agent';

    public const FACT_KEYS = [
        'order.found', 'order.identity_match', 'order.status', 'order.shipping_status',
        'shipment.carrier', 'shipment.tracking_available', 'shipment.logistics_status',
        'shipment.shipped_at', 'shipment.delivered_at',
    ];

    public function __construct(
        private readonly TicketRuntimeContext $context,
        private readonly TicketDraftInput $inputs,
        private readonly TicketFactResolver $facts,
        private readonly KnowledgeResolver $knowledge,
        private readonly TicketPolicyRepository $policies,
    ) {}

    public function capture(Conversation $conversation): array
    {
        if ($conversation->silo !== 'tickets' || $conversation->source_type === 'replay') {
            throw new RuntimeException('This investigation requires a non-replay Ticket.');
        }
        $before = $this->fingerprint($conversation);
        $trigger = $this->context->triggerEvent($conversation);
        $ticket = $this->context->build($conversation, $trigger);
        $resolved = $this->facts->resolve($conversation, $trigger, ['intents' => ['logistics_tracking_delivery']]);
        $safeFacts = $this->shareableFacts($resolved['facts']);
        $identity = collect($safeFacts)->firstWhere('key', 'order.identity_match');
        $blocked = $identity === null || $identity['value'] !== true || $resolved['safety_flags'] !== [];
        $policies = $this->knowledge->forIntents('ticket', $conversation->site_key, ['logistics_tracking_delivery'])
            ->sortByDesc(fn ($article): int => $article->key === 'shipped_order_tracking_and_eta_communication' ? 2
                : ($article->bindings->pluck('intent_key')->contains('logistics_tracking_delivery') ? 1 : 0))
            ->take(8)->map(fn ($article): array => [
                ...$this->policies->payload($article), 'status' => 'verified',
            ])->all();
        $this->assertCurrent($conversation, $before);
        $snapshot = [
            'schema_version' => '1.0',
            'case_key' => 'ticket:'.$conversation->id,
            'captured_at' => now()->toIso8601String(),
            'source_input_hash' => $before,
            'messages' => collect($ticket['messages'])->map(fn (array $message): array => [
                'role' => $message['role'], 'text' => $message['text'],
            ])->all(),
            'facts' => $blocked ? [] : $safeFacts,
            'policies' => $policies,
            'missing_facts' => array_values(array_unique([
                ...$resolved['missing_facts'], ...($blocked ? ['order.identity_verified'] : []),
            ])),
            // Provider exception text can contain identifiers; use a stable code instead.
            'safety_flags' => $blocked ? ['order_identity_or_lookup_unverified'] : [],
        ];

        return [...$snapshot, 'input_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }

    public function shareableFacts(array $facts): array
    {
        $facts = array_values(array_filter($facts, function (array $fact): bool {
            if (! in_array($fact['key'] ?? null, self::FACT_KEYS, true)
                || ! in_array($fact['sensitivity'] ?? null, ['public', 'internal'], true)
                || ($fact['shareable_with_ai'] ?? false) !== true
                || ! is_scalar($fact['value'] ?? null)
                || blank($fact['source'] ?? null)
                || blank($fact['captured_at'] ?? null)
                || blank($fact['fresh_until'] ?? null)) {
                return false;
            }

            try {
                return Carbon::parse($fact['captured_at'])->lte(now())
                    && Carbon::parse($fact['fresh_until'])->gt(now());
            } catch (\Throwable) {
                return false;
            }
        }));

        return array_map(fn (array $fact): array => Arr::only($fact, [
            'schema_version', 'key', 'value', 'source', 'captured_at', 'fresh_until', 'sensitivity', 'shareable_with_ai',
        ]), $facts);
    }

    public function assertCurrent(Conversation $conversation, string $hash): void
    {
        if (! hash_equals($hash, $this->fingerprint($conversation->fresh()))) {
            throw new RuntimeException('Ticket inputs or verified knowledge changed during investigation; prepare a new run.');
        }
    }

    private function fingerprint(Conversation $conversation): string
    {
        return hash('sha256', json_encode([
            'draft_input_hash' => $this->inputs->capture($conversation, 0, self::WORKFLOW_KEY, '0.1.0')['hash'],
            'identity_scope' => $conversation->only(['channel_account_id', 'source_type', 'requester_email', 'requester_snapshot', 'subject']),
        ], JSON_THROW_ON_ERROR));
    }
}
