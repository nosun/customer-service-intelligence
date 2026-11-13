<?php

namespace App\Services\CustomerContacts;

use App\Models\CustomerContactEffect;
use App\Models\CustomerContactRound;
use App\Models\WorkItem;
use App\Services\N8n\WorkflowRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MultiDressMailExecutor
{
    public function __construct(private readonly MultiDressInput $input, private readonly MultiDressPolicy $policy) {}

    public function send(int $roundId): array
    {
        $round = CustomerContactRound::findOrFail($roundId);
        if (config('customer_contacts.mode') !== 'live' || ! config('customer_contacts.send_enabled')
            || ! config('customer_contacts.sites.'.$round->site_key.'.send_enabled')) {
            return ['decision' => 'disabled'];
        }
        if (in_array($round->communication_state, ['sent', 'sending', 'send_unknown'], true)) {
            return ['decision' => 'already_attempted'];
        }
        $current = $this->input->capture($round);
        if ($round->human_hold_at || $round->communication_state !== 'ready' || ! $round->approved_input_hash
            || $current['hash'] !== $round->approved_input_hash
            || $this->policy->assess($current['snapshot'], $current['communication'])['decision'] !== 'prepare_first_email') {
            return ['decision' => 'stale'];
        }
        $work = WorkItem::find($round->work_item_id);
        if (! $work || $work->status !== 'completed' || $work->work_type !== 'multi_dress_first_round'
            || $work->input_hash !== $round->approved_input_hash || data_get($work->result, 'decision') !== 'ready') {
            throw new RuntimeException('No completed reviewed first-round work item.');
        }
        $workflow = app(WorkflowRegistry::class)->active($work->workflow_key, $work->workflow_version);
        $review = data_get($work->result, 'review', []);
        if ($workflow->center !== 'work' || $workflow->domain !== 'customer_contacts'
            || $work->workflow_key !== 'work/multi-dress-first-round-v1'
            || $work->workflow_version !== config('customer_contacts.workflow_version')
            || $workflow->definition_checksum !== hash_file('sha256', base_path('n8n/workflows/work__multi-dress-first-round-v1.json'))
            || ($review['approved'] ?? false) !== true || ($review['violations'] ?? null) !== []
            || ($review['reviewed_input_hash'] ?? null) !== $round->approved_input_hash
            || ($review['reviewed_content_hash'] ?? null) !== $round->approved_content_hash
            || hash('sha256', json_encode($review, JSON_THROW_ON_ERROR)) !== $round->review_hash) {
            throw new RuntimeException('Workflow definition or independent review proof does not match the frozen email.');
        }
        $mail = json_decode(Storage::disk('local')->get($round->approved_mail_ref), true, 512, JSON_THROW_ON_ERROR);
        if (MultiDressTemplate::contentHash($mail) !== $round->approved_content_hash || $mail['input_hash'] !== $round->approved_input_hash
            || $mail['to'] !== $current['snapshot']['customer']['email'] || $mail['marker'] !== $round->round_key
            || $mail['from'] !== config('gmail.accounts.'.$round->site_key.'.email') || $mail['site_key'] !== $round->site_key) {
            throw new RuntimeException('Frozen email or identity no longer matches review.');
        }
        app(GmailFirstRoundTransport::class)->verifyAccount($round->site_key);
        $effect = DB::transaction(function () use ($round, $mail) {
            $locked = CustomerContactRound::lockForUpdate()->findOrFail($round->id);
            if ($locked->communication_state !== 'ready' || $locked->human_hold_at || $locked->approved_input_hash !== $round->approved_input_hash
                || $locked->source_hash !== $round->source_hash) {
                return null;
            }
            $effect = CustomerContactEffect::firstOrCreate(['round_id' => $round->id, 'effect_kind' => 'first_email'],
                ['state' => 'prepared', 'payload_hash' => $round->approved_content_hash, 'payload' => $mail, 'idempotency_key' => (string) Str::uuid()]);
            if ($effect->state !== 'prepared') {
                return null;
            }
            $effect->update(['state' => 'sending', 'attempt_started_at' => now()]);
            $locked->update(['communication_state' => 'sending']);

            return $effect;
        });
        if (! $effect) {
            return ['decision' => 'already_attempted'];
        }
        try {
            $receipt = app(GmailFirstRoundTransport::class)->send($effect->payload);
        } catch (Throwable) {
            $receipt = ['state' => 'unknown'];
        }

        return $this->record($effect, $receipt);
    }

    public function reconcile(int $effectId): array
    {
        $effect = CustomerContactEffect::findOrFail($effectId);
        if ($effect->effect_kind !== 'first_email' || ! in_array($effect->state, ['sending', 'unknown'], true)) {
            return ['decision' => 'not_unknown'];
        }
        if (! in_array(config('customer_contacts.mode'), ['shadow', 'live'], true)) {
            return ['decision' => 'disabled'];
        }
        try {
            $receipt = app(GmailFirstRoundTransport::class)->reconcile($effect);
        } catch (Throwable) {
            $receipt = ['state' => 'unknown'];
        }

        return $this->record($effect, $receipt);
    }

    private function record(CustomerContactEffect $effect, array $receipt): array
    {
        $matched = ($receipt['state'] ?? '') === 'matched' && ! empty($receipt['gmail_id']) && ! empty($receipt['rfc822_id']) && ! empty($receipt['sent_at']);

        return DB::transaction(function () use ($effect, $receipt, $matched) {
            $round = CustomerContactRound::lockForUpdate()->findOrFail($effect->round_id);
            $effect = CustomerContactEffect::lockForUpdate()->findOrFail($effect->id);
            if ($effect->state === 'succeeded') {
                return ['decision' => 'sent'];
            }
            if (! $matched) {
                $effect->update(['state' => 'unknown', 'error_code' => 'SEND_RECONCILIATION_REQUIRED']);
                $round->update(['communication_state' => 'send_unknown']);

                return ['decision' => 'send_unknown'];
            }
            $sentAt = CarbonImmutable::parse($receipt['sent_at'])->utc();
            $effect->update(['state' => 'succeeded', 'receipt' => $receipt, 'error_code' => null]);
            $round->update(['communication_state' => 'sent', 'sent_at' => $round->sent_at ?? $sentAt,
                'wait_until' => $round->wait_until ?? $sentAt->addHours(48), 'waiting_state' => $round->human_hold_at ? 'held' : 'waiting', 'note_state' => 'pending']);
            CustomerContactEffect::firstOrCreate(['round_id' => $round->id, 'effect_kind' => 'sent_note'],
                ['payload_hash' => hash('sha256', $round->round_key), 'payload' => [], 'idempotency_key' => (string) Str::uuid()]);
            $round->events()->firstOrCreate(['event_key' => 'sent:'.$effect->id], ['effect_id' => $effect->id, 'event_type' => 'gmail_sent_verified',
                'actor' => 'nosun-agent', 'occurred_at' => $sentAt, 'evidence' => $receipt]);

            return ['decision' => 'sent'];
        });
    }
}
