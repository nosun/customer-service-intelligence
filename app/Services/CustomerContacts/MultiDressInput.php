<?php

namespace App\Services\CustomerContacts;

use App\Models\CustomerContactRound;

class MultiDressInput
{
    public function __construct(private readonly ConsoleContactReader $console, private readonly CommunicationReader $communication) {}

    public function capture(CustomerContactRound $round): array
    {
        $snapshot = $this->console->snapshot($round->order_number);
        $communication = $this->communication->capture([...$snapshot['identity'], 'email' => $snapshot['customer']['email'] ?? ''],
            $round->sent_at ?? now()->toImmutable()->subDays(30));
        $snapshot['trigger'] = $round->trigger_snapshot; // Never upgrade original trigger proof on a later scan.

        return ['snapshot' => $snapshot, 'communication' => $communication,
            'hash' => self::hash(['snapshot' => $snapshot, 'communication' => $communication])];
    }

    public static function hash(array $input): string
    {
        // Capture timestamps establish freshness, not business identity. Message IDs and all human edits remain hashed.
        unset($input['snapshot']['captured_at'], $input['snapshot']['request_ids'], $input['communication']['checkpoint']['captured_at']);
        $input['template_version'] = MultiDressTemplate::VERSION;
        $input['workflow_version'] = config('customer_contacts.workflow_version');
        $definition = base_path('n8n/workflows/work__multi-dress-first-round-v1.json');
        $input['workflow_checksum'] = is_file($definition) ? hash_file('sha256', $definition) : null;
        $input['ai'] = [config('services.draft_ai.provider'), config('services.omniroute.model'), config('services.minimax.model')];
        $input['account'] = config('gmail.accounts.'.data_get($input, 'snapshot.identity.site_key').'.email');

        return hash('sha256', json_encode(self::canonical($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonical(array $a): array
    {
        if (! array_is_list($a)) {
            ksort($a);
        }
        foreach ($a as &$v) {
            if (is_array($v)) {
                $v = self::canonical($v);
            }
        }

        return $a;
    }
}
