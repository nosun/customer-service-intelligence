<?php

namespace Tests\Support;

class MultiDressScenario
{
    public static function snapshot(): array
    {
        return [
            'schema_version' => 'multi_dress.first_round.input.v1',
            'identity' => ['console_key' => 'seawave', 'site_key' => 'ellenbridals', 'sid' => 36, 'oid' => 900001, 'order_number' => 'ebd20990101-1'],
            'customer' => ['email' => 'customer@example.invalid'],
            'order' => ['paid' => true, 'cancelled' => false, 'shipped' => false, 'production_started' => false, 'on_hold' => true],
            'items' => [
                ['id' => 101, 'type' => 'dress', 'active' => true, 'item_code' => 'TEST-A', 'color' => 'As picture', 'size' => '2', 'quantity' => 1],
                ['id' => 102, 'type' => 'dress', 'active' => true, 'item_code' => 'TEST-B', 'color' => 'Black', 'size' => '2', 'quantity' => 1],
            ],
            'contacts' => [['id' => 301, 'source' => 'multi_dress', 'version' => 1, 'released' => false, 'deleted' => false, 'internal_note' => '', 'status' => 'customer']],
            'trigger' => ['verified' => true, 'original_count' => 2, 'item_ids' => [101, 102], 'rule_version' => 'fixture-v1'],
            'human_hold' => false, 'other_blockers' => [], 'intent_complete' => false,
            'policy' => ['verified' => true, 'version' => 'fixture-v1', 'url' => 'https://www.ellenbridals.com/article/return-policy.html'],
        ];
    }

    public static function communication(): array
    {
        return ['complete' => true, 'fresh' => true, 'identity_unique' => true, 'prior_contact' => false,
            'reply_seen' => false, 'delivery_failure' => false, 'human_takeover' => false,
            'evidence_refs' => [], 'checkpoint' => ['captured_at' => now()->toIso8601String(), 'gmail_complete' => true, 'helpdesk_complete' => true]];
    }
}
