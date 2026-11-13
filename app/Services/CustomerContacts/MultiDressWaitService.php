<?php

namespace App\Services\CustomerContacts;

use App\Models\CustomerContactRound;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Throwable;

class MultiDressWaitService
{
    public function __construct(private readonly MultiDressInput $input, private readonly MultiDressPolicy $policy,
        private readonly MultiDressHandoffService $handoffs) {}

    public function inspect(int $roundId, CarbonImmutable $at): array
    {
        $round = CustomerContactRound::findOrFail($roundId);
        if ($round->communication_state !== 'sent' || ! $round->sent_at || ! $round->wait_until) {
            return ['decision' => 'held', 'reason' => 'send_not_verified'];
        }
        if ($round->waiting_state === 'reply_seen') {
            return ['decision' => 'human_handoff', 'reason' => 'reply_seen'];
        }
        try {
            $current = $this->input->capture($round);
        } catch (Throwable) {
            return $this->hold($round, 'communication_read_failed');
        }
        $s = $current['snapshot'];
        $c = $current['communication'];
        if ($c['reply_seen'] ?? false) {
            $round->update(['waiting_state' => 'reply_seen']);
            $this->handoffs->upsert($round->id, '收到相关客户回复，停止无回复路径', $c['evidence_refs'] ?? []);

            return ['decision' => 'human_handoff', 'reason' => 'reply_seen'];
        }
        if ($round->human_hold_at || ($s['human_hold'] ?? true) || ($c['human_takeover'] ?? true)) {
            return $this->hold($round, 'human_takeover');
        }
        if ($at->lessThan($round->wait_until)) {
            return ['decision' => 'waiting', 'reason' => 'not_due'];
        }
        $trigger = $round->trigger_snapshot ?? [];
        if (($trigger['original_count'] ?? 0) >= 3) {
            return $this->hold($round, 'original_three_or_more');
        }
        if (($trigger['verified'] ?? false) !== true || ($trigger['original_count'] ?? null) !== 2) {
            return $this->hold($round, 'original_trigger_unproven');
        }
        $approved = data_get(WorkItem::find($round->work_item_id)?->input_snapshot, 'snapshot');
        if (! is_array($approved) || $this->policy->count($s) !== 2 || $this->scope($s) !== $this->scope($approved)) {
            return $this->hold($round, 'item_scope_changed');
        }
        $originalIds = $trigger['item_ids'] ?? [];
        $currentIds = array_column($this->policy->dresses($s), 'id');
        sort($originalIds);
        sort($currentIds);
        if ($originalIds !== $currentIds || empty($trigger['rule_version'])) {
            return $this->hold($round, 'original_trigger_unproven');
        }
        foreach (['complete' => 'communication_incomplete', 'fresh' => 'communication_stale', 'identity_unique' => 'identity_ambiguous'] as $key => $reason) {
            if (($c[$key] ?? false) !== true) {
                return $this->hold($round, $reason);
            }
        }
        $capturedAt = data_get($c, 'checkpoint.captured_at');
        if (! is_string($capturedAt) || abs($at->diffInSeconds(CarbonImmutable::parse($capturedAt))) > config('customer_contacts.communication_fresh_seconds')) {
            return $this->hold($round, 'communication_stale');
        }
        if ($c['delivery_failure'] ?? true) {
            return $this->hold($round, 'delivery_failure');
        }
        if (! empty($s['other_blockers'])) {
            return $this->hold($round, 'other_blockers');
        }
        if ($round->note_state !== 'recorded') {
            return $this->hold($round, 'console_note_not_recorded');
        }
        $targets = collect($s['contacts'] ?? [])->filter(fn ($c) => in_array($c['id'] ?? null, $round->contact_ids, true));
        if ($targets->count() !== count($round->contact_ids) || $targets->contains(fn ($c) => ($c['source'] ?? '') !== 'multi_dress'
            || ($c['released'] ?? true) || ($c['deleted'] ?? true))) {
            return $this->hold($round, 'target_contact_changed');
        }
        $note = $round->effects()->where('effect_kind', 'sent_note')->first();
        $writtenContact = $targets->firstWhere('id', data_get($note?->payload, 'contact_id'));
        if (! $writtenContact || data_get($note?->receipt, 'contact_baseline') !== MultiDressConsoleExecutor::contactBaseline($writtenContact)) {
            return $this->hold($round, 'contact_changed_after_note');
        }
        foreach ($targets as $target) {
            if ($target['id'] !== $writtenContact['id']) {
                $before = collect($approved['contacts'] ?? [])->firstWhere('id', $target['id']);
                if (! $before || MultiDressConsoleExecutor::contactBaseline($before) !== MultiDressConsoleExecutor::contactBaseline($target)) {
                    return $this->hold($round, 'target_contact_changed');
                }
            }
        }
        if (($s['order_notes'] ?? []) !== ($approved['order_notes'] ?? [])) {
            return $this->hold($round, 'order_notes_changed');
        }
        $o = $s['order'] ?? [];
        if (($o['paid'] ?? false) !== true || ($o['cancelled'] ?? true) || ($o['shipped'] ?? true)
            || ($o['production_started'] ?? true) || ($o['on_hold'] ?? false) !== true) {
            return $this->hold($round, 'order_not_eligible');
        }
        try {
            $identity = $this->policy->identity($s);
        } catch (Throwable) {
            return $this->hold($round, 'identity_changed');
        }
        if ((int) $identity['oid'] !== (int) $round->oid || (int) $identity['sid'] !== (int) $round->sid
            || $identity['site_key'] !== $round->site_key || $identity['order_number'] !== $round->order_number
            || data_get($s, 'customer.email') !== data_get($approved, 'customer.email')) {
            return $this->hold($round, 'identity_changed');
        }
        if (data_get($s, 'policy.verified') !== true || data_get($s, 'policy.version') !== $round->policy_version
            || data_get($s, 'policy.version') !== data_get($approved, 'policy.version')
            || data_get($s, 'policy.url') !== data_get($approved, 'policy.url')) {
            return $this->hold($round, 'policy_changed');
        }
        $round->update(['waiting_state' => 'due']);

        return ['decision' => 'timeout_preview_ready', 'reason' => 'two_dress_48h_no_reply', 'execution_available' => false];
    }

    private function scope(array $s): string
    {
        $items = $this->policy->dresses($s);
        usort($items, fn ($a, $b) => $a['id'] <=> $b['id']);

        return hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
    }

    private function hold(CustomerContactRound $round, string $reason): array
    {
        $round->update(['waiting_state' => 'held']);
        $this->handoffs->upsert($round->id, $reason, []);

        return ['decision' => 'held', 'reason' => $reason];
    }
}
