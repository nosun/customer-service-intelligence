<?php

namespace App\Services\TicketWork;

use Illuminate\Validation\ValidationException;

class TicketDraftPlanGuard
{
    public const SUPPORTED_INTENTS = [
        'production_order_status',
        'logistics_tracking_delivery',
    ];

    /** @return array<string,mixed> */
    public function validate(array $plan): array
    {
        $intents = [];
        $clauses = array_values(array_filter((array) ($plan['clauses'] ?? []), 'is_array'));
        $unsupported = array_values(array_filter((array) ($plan['unsupported_requests'] ?? []), 'is_string'));
        $policyKeys = array_values(array_unique(array_filter((array) ($plan['policy_keys'] ?? []), 'is_string')));
        if ($clauses === []) {
            throw ValidationException::withMessages(['plan' => 'Ticket plan must include at least one customer clause.']);
        }
        if (count($policyKeys) > 8) {
            throw ValidationException::withMessages(['plan' => 'Ticket plan exceeds the supported intent or Policy limit.']);
        }
        foreach ($clauses as &$clause) {
            if (trim((string) ($clause['text'] ?? '')) === '') {
                throw ValidationException::withMessages(['plan' => 'Every Ticket clause must include text.']);
            }
            $intent = (string) ($clause['intent'] ?? '');
            if (in_array($intent, self::SUPPORTED_INTENTS, true)) {
                $intents[] = $intent;
            } else {
                $clause['intent'] = 'unsupported';
                $intent = 'unsupported';
            }
            if ($intent === 'unsupported') {
                $unsupported[] = trim((string) $clause['text']);
            }
        }
        unset($clause);
        $intents = array_values(array_unique($intents));

        return [
            ...$plan,
            'clauses' => $clauses,
            'intents' => $intents,
            'policy_keys' => $policyKeys,
            'unsupported_requests' => array_values(array_unique($unsupported)),
            'customer_language' => mb_substr((string) ($plan['customer_language'] ?? 'en'), 0, 10),
        ];
    }

    /** @param list<string> $missingFacts @return list<string> */
    public function blockingReasons(array $plan, array $missingFacts, int $policyCount): array
    {
        $reasons = [];
        if (($plan['unsupported_requests'] ?? []) !== []) {
            $reasons[] = 'The Ticket contains a request outside the released workflow scope.';
        }
        if ($missingFacts !== []) {
            $reasons[] = 'Required current order, production, or shipment facts are missing.';
        }
        if ($policyCount === 0) {
            $reasons[] = 'No verified Policy was selected for this Ticket.';
        }

        return $reasons;
    }
}
