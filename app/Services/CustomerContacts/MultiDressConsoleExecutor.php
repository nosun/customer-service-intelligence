<?php

namespace App\Services\CustomerContacts;

use App\Models\CustomerContactEffect;
use App\Models\CustomerContactRound;
use App\Services\ConsoleNew\ConsoleNewApiException;
use App\Services\ConsoleNew\ConsoleNewConnections;
use Carbon\CarbonImmutable;
use Encore\Admin\Auth\Database\Administrator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MultiDressConsoleExecutor
{
    public function __construct(private readonly ConsoleContactReader $reader, private readonly ConsoleNewConnections $connections) {}

    public function previewNote(int $roundId): array
    {
        $this->enabled();
        $round = CustomerContactRound::findOrFail($roundId);
        if ($round->console_key !== 'seawave' || $round->communication_state !== 'sent' || $round->human_hold_at) {
            throw new RuntimeException('Only sent Seawave first rounds may append a note.');
        }
        $contact = $this->reader->contact((int) ($round->contact_ids[0] ?? 0));
        $this->sameContact($round, $contact);
        $marker = '[nosun-agent]['.$round->round_key.']';
        if (str_contains($contact['internal_note'] ?? '', $marker)) {
            $effect = $round->effects()->where('effect_kind', 'sent_note')->first();
            if ($effect && $effect->state !== 'succeeded') {
                $effect->update(['state' => 'succeeded', 'receipt' => ['contact_baseline' => self::contactBaseline($contact)]]);
            }
            $round->update(['note_state' => 'recorded']);

            return ['decision' => 'already_recorded'];
        }
        $note = rtrim($contact['internal_note'] ?? '');
        $note .= ($note === '' ? '' : "\n").$marker.' 多件首信已由Gmail发送并回查；等待48小时确认用途及保留计划，后续由HelpDesk人工接续。';
        $payload = ['preview' => true, 'reason' => '多件确认首轮发信记录', 'version' => (int) $contact['version'],
            'changes' => ['internal_note' => $note, 'recorder' => 'nosun-agent']];
        $effect = DB::transaction(function () use ($round, $payload, $contact) {
            CustomerContactRound::lockForUpdate()->findOrFail($round->id);
            $effect = CustomerContactEffect::firstOrCreate(['round_id' => $round->id, 'effect_kind' => 'sent_note'],
                ['payload_hash' => str_repeat('0', 64), 'payload' => [], 'idempotency_key' => (string) Str::uuid()]);
            if (in_array($effect->state, ['sending', 'unknown', 'succeeded', 'previewing'], true)) {
                throw new RuntimeException('Existing contact operation must be reconciled first.');
            }
            $effect->update(['state' => 'previewing', 'payload' => ['contact_id' => $contact['id'], 'request' => $payload],
                'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'authorized_by' => null, 'authorized_at' => null, 'authorized_payload_hash' => null]);

            return $effect;
        });
        try {
            $response = $this->connections->connection('seawave')->updateContactNote($contact['id'], $payload)['data'];
            if (($response['allowed'] ?? false) !== true || empty($response['confirm_token']) || empty($response['diff']) || empty($response['expires_at'])
                || data_get($response, 'diff.after.internal_note') !== $note) {
                throw new RuntimeException('Incomplete Console preview contract.');
            }
            $effect->update(['state' => 'previewed', 'preview' => $response, 'operation_id' => $response['operation_id'] ?? null,
                'expires_at' => CarbonImmutable::parse($response['expires_at'])]);
        } catch (Throwable $e) {
            $effect->update(['state' => 'failed', 'error_code' => 'NOTE_PREVIEW_FAILED']);
            $round->update(['note_state' => 'failed']);
            throw $e;
        }

        return ['decision' => 'previewed', 'effect_id' => $effect->id, 'payload_hash' => $effect->payload_hash, 'preview' => $response];
    }

    public function confirm(int $effectId, Administrator $actor, string $previewHash): array
    {
        if (! $actor->exists || ! ($actor->isAdministrator() || $actor->can('customer-contacts.execute'))) {
            throw new AuthorizationException('A signed-in authorized human must confirm the actual preview.');
        }
        $this->enabled();
        $effect = CustomerContactEffect::findOrFail($effectId);
        $round = CustomerContactRound::findOrFail($effect->round_id);
        if ($effect->effect_kind !== 'sent_note' || $effect->state !== 'previewed' || $previewHash !== $effect->payload_hash
            || $effect->expires_at?->isPast() || ! $effect->expires_at || $round->human_hold_at || $round->console_key !== 'seawave') {
            throw new AuthorizationException('Preview is stale, unavailable, or not the authorized payload.');
        }
        $contact = $this->reader->contact($effect->payload['contact_id']);
        $this->sameContact($round, $contact);
        if ((int) $contact['version'] !== $effect->payload['request']['version']) {
            $effect->update(['state' => 'stale']);
            $round->update(['note_state' => 'failed']);
            throw new RuntimeException('Contact changed; read and preview again.');
        }
        DB::transaction(function () use ($effect, $actor, $previewHash) {
            $round = CustomerContactRound::lockForUpdate()->findOrFail($effect->round_id);
            $locked = CustomerContactEffect::lockForUpdate()->findOrFail($effect->id);
            if ($round->human_hold_at || $locked->state !== 'previewed' || $locked->payload_hash !== $previewHash || $locked->expires_at->isPast()) {
                throw new AuthorizationException('Preview changed during confirmation.');
            }
            $locked->update(['state' => 'sending', 'authorized_by' => $actor->id, 'authorized_at' => now(),
                'authorized_payload_hash' => $previewHash, 'attempt_started_at' => now()]);
        });
        try {
            $payload = [...$effect->payload['request'], 'preview' => false, 'confirm_token' => $effect->preview['confirm_token'],
                'idempotency_key' => $effect->idempotency_key];
            $this->connections->connection('seawave')->updateContactNote($effect->payload['contact_id'], $payload);

            return $this->reconcile($effect->id);
        } catch (Throwable $e) {
            if ($e instanceof ConsoleNewApiException && in_array($e->statusCode, [409, 422], true)
                && in_array($e->errorCode, ['CONFIRM_TOKEN_STALE', 'CONTACT_CONFLICT'], true)) {
                // The source explicitly rejected the write. A fresh preview may safely preserve new human content.
                $effect->update(['state' => 'stale', 'error_code' => $e->errorCode]);
                $round->update(['note_state' => 'failed']);

                return ['decision' => 'stale'];
            }
            $effect->update(['state' => 'unknown', 'error_code' => 'NOTE_CONFIRM_UNCERTAIN']);
            $round->update(['note_state' => 'unknown']);

            return ['decision' => 'unknown'];
        }
    }

    public function reconcile(int $effectId): array
    {
        $effect = CustomerContactEffect::findOrFail($effectId);
        if ($effect->effect_kind !== 'sent_note' || ! in_array($effect->state, ['unknown', 'sending', 'succeeded'], true)
            || ! in_array(config('customer_contacts.mode'), ['shadow', 'live'], true)) {
            return ['decision' => 'not_reconcilable'];
        }
        $round = CustomerContactRound::findOrFail($effect->round_id);
        $contact = $this->reader->contact($effect->payload['contact_id']);
        $this->sameContact($round, $contact);
        if (! str_contains($contact['internal_note'] ?? '', '[nosun-agent]['.$round->round_key.']')) {
            return ['decision' => 'unknown'];
        }
        // Preserve the first verified post-write baseline: a later human edit must never be blessed by reconciliation.
        $receipt = $effect->receipt ?? [];
        $receipt['contact_baseline'] ??= self::contactBaseline($contact);
        $effect->update(['state' => 'succeeded', 'error_code' => null, 'receipt' => $receipt]);
        $round->update(['note_state' => 'recorded']);

        return ['decision' => 'recorded'];
    }

    public function previewTimeout(int $roundId): array
    {
        CustomerContactRound::findOrFail($roundId);

        return ['decision' => 'held', 'reason' => 'source_timeout_contract_missing'];
    }

    public static function contactBaseline(array $contact): string
    {
        $fields = [];
        foreach (['version', 'internal_note', 'status', 'customer_requirement', 'recorder', 'released_at', 'deleted_at', 'blocks_assignment'] as $key) {
            $fields[$key] = $key === 'version' ? (int) ($contact[$key] ?? 0) : ($contact[$key] ?? null);
        }

        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
    }

    private function sameContact(CustomerContactRound $round, array $c): void
    {
        if ((int) ($c['oid'] ?? 0) !== (int) $round->oid || ! in_array((int) ($c['id'] ?? 0), $round->contact_ids, true)
            || ($c['source'] ?? '') !== 'multi_dress' || ! empty($c['deleted_at'])) {
            throw new RuntimeException('Contact identity does not match the first round.');
        }
    }

    private function enabled(): void
    {
        if (config('customer_contacts.mode') !== 'live' || ! config('customer_contacts.console_note_enabled')) {
            throw new RuntimeException('Console note execution is disabled.');
        }
    }
}
