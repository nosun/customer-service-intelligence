<?php

namespace App\Services\CustomerContacts;

use Carbon\CarbonImmutable;
use Throwable;

class CommunicationReader
{
    public function __construct(private readonly GmailCommunicationSource $gmail, private readonly HelpDeskCommunicationSource $helpdesk) {}

    public function capture(array $identity, CarbonImmutable $since): array
    {
        $sources = [];
        foreach (['gmail' => $this->gmail, 'helpdesk' => $this->helpdesk] as $key => $source) {
            try {
                $sources[$key] = $source->capture($identity, $since);
            } catch (Throwable) {
                $sources[$key] = ['complete' => false, 'messages' => [], 'human_takeover' => false, 'checkpoint' => ['reason' => 'read_failed']];
            }
        }
        $messages = [...$sources['gmail']['messages'], ...$sources['helpdesk']['messages']];
        $kinds = array_column($messages, 'kind');
        $complete = $sources['gmail']['complete'] === true && $sources['helpdesk']['complete'] === true;

        return ['complete' => $complete, 'fresh' => $complete, 'identity_unique' => ! array_intersect($kinds, ['ambiguous', 'automatic']),
            'prior_contact' => in_array('prior_outbound', $kinds, true), 'reply_seen' => in_array('customer_reply', $kinds, true),
            'delivery_failure' => in_array('delivery_failure', $kinds, true),
            'human_takeover' => $sources['gmail']['human_takeover'] || $sources['helpdesk']['human_takeover'],
            'evidence_refs' => array_column($messages, 'id'),
            'checkpoint' => ['captured_at' => now()->toIso8601String(), 'gmail_complete' => $sources['gmail']['complete'],
                'helpdesk_complete' => $sources['helpdesk']['complete'], 'sources' => $sources]];
    }
}
