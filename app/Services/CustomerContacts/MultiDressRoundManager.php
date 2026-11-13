<?php

namespace App\Services\CustomerContacts;

use App\Models\CustomerCase;
use App\Models\CustomerCaseSource;
use App\Models\CustomerContactRound;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MultiDressRoundManager
{
    public function __construct(private readonly MultiDressPolicy $policy) {}

    public function ingest(array $snapshot, array $communication): CustomerContactRound
    {
        $i = $this->policy->identity($snapshot);
        $assessment = $this->policy->assess($snapshot, $communication);

        return DB::transaction(function () use ($i, $snapshot, $communication, $assessment) {
            $case = CustomerCase::firstOrCreate(['case_key' => "multi_dress:seawave:{$i['sid']}:{$i['oid']}"],
                ['site_key' => $i['site_key'], 'case_type' => 'multi_dress_first_round', 'title' => $i['order_number'], 'queue' => 'customer_contacts']);
            CustomerCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            CustomerCaseSource::updateOrCreate(['source_type' => 'console_order', 'source_id' => "console:seawave:{$i['sid']}:{$i['oid']}"],
                ['customer_case_id' => $case->id, 'snapshot' => $snapshot]);
            $round = CustomerContactRound::firstOrCreate(['console_key' => 'seawave', 'sid' => $i['sid'], 'oid' => $i['oid'], 'round_kind' => 'first_round'],
                ['round_key' => (string) Str::uuid(), 'customer_case_id' => $case->id, 'site_key' => $i['site_key'], 'order_number' => $i['order_number'],
                    'source_hash' => str_repeat('0', 64), 'trigger_snapshot' => $snapshot['trigger'] ?? ['verified' => false]]);
            $round = CustomerContactRound::lockForUpdate()->findOrFail($round->id);
            $snapshot['trigger'] = $round->trigger_snapshot;
            $hash = MultiDressInput::hash(['snapshot' => $snapshot, 'communication' => $communication]);
            $changes = ['contact_ids' => array_column(array_values(array_filter($snapshot['contacts'] ?? [], fn ($c) => ($c['source'] ?? '') === 'multi_dress' && ! ($c['deleted'] ?? true))), 'id'), 'source_snapshot' => $snapshot,
                'source_hash' => $hash, 'communication_checkpoint' => $communication, 'assessment' => $assessment,
                'policy_version' => $snapshot['policy']['version'] ?? null, 'version' => $round->version + 1];
            if (! in_array($round->communication_state, ['sending', 'sent', 'send_unknown'], true)) {
                $changes['communication_state'] = match ($assessment['decision']) {
                    'skip' => 'suppressed', 'manual' => 'manual', 'not_needed' => 'not_needed', default => 'new',
                };
                if ($round->source_hash === $hash && $round->communication_state === 'ready') {
                    $changes['communication_state'] = 'ready';
                }
            } else {
                unset($changes['policy_version']); // Preserve the policy bound to the attempted first email.
            }
            $round->update($changes);

            return $round->refresh();
        });
    }

    public function requestWork(CustomerContactRound $round): WorkItem
    {
        return DB::transaction(function () use ($round) {
            $round = CustomerContactRound::lockForUpdate()->findOrFail($round->id);
            if ($round->human_hold_at || ! in_array($round->communication_state, ['new', 'ready'], true)
                || data_get($round->assessment, 'decision') !== 'prepare_first_email') {
                throw new RuntimeException('Round is not eligible for draft work.');
            }
            $existing = WorkItem::where('customer_case_id', $round->customer_case_id)->where('work_type', 'multi_dress_first_round')
                ->where('input_hash', $round->source_hash)->whereIn('status', ['queued', 'running', 'completed'])->latest('id')->first();
            if ($existing) {
                return $existing;
            }
            $item = WorkItem::create(['work_item_key' => (string) Str::uuid(), 'customer_case_id' => $round->customer_case_id,
                'work_type' => 'multi_dress_first_round', 'workflow_key' => 'work/multi-dress-first-round-v1',
                'workflow_version' => config('customer_contacts.workflow_version'), 'input_hash' => $round->source_hash,
                'input_snapshot' => ['round_id' => $round->id, 'snapshot' => $round->source_snapshot, 'communication' => $round->communication_checkpoint],
                'deadline_at' => now()->addMinutes(15)]);
            $round->update(['work_item_id' => $item->id]);

            return $item;
        });
    }
}
