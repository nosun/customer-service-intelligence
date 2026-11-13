<?php

namespace App\Services\CustomerContacts;

use App\Services\Gmail\GwsClient;
use Carbon\CarbonImmutable;
use RuntimeException;

class GmailCommunicationSource
{
    public function capture(array $identity, CarbonImmutable $since): array
    {
        $email = strtolower($identity['email'] ?? '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\s<>"\r\n]/', $email)) {
            throw new RuntimeException('Customer email is invalid.');
        }
        $account = config('gmail.accounts.'.$identity['site_key']);
        $client = new GwsClient($account['config_dir'], maxAttempts: 1);
        if (strcasecmp($client->profile()['emailAddress'] ?? '', $account['email']) !== 0) {
            throw new RuntimeException('Gmail account does not match site.');
        }
        // Customer address across all folders catches new subjects; also inspect order-related forwards/DSNs.
        $q = 'in:anywhere after:'.max(0, $since->timestamp - 86400).' {from:'.$email.' to:'.$email.' "'.$identity['order_number'].'"}';
        $ids = $client->listMessageIds($q, (int) config('customer_contacts.page_limit'));
        $messages = [];
        foreach ($ids as $id) {
            $m = $client->getMessage($id);
            $h = [];
            foreach ($m['payload']['headers'] ?? [] as $header) {
                $h[strtolower($header['name'])] = $header['value'];
            }
            $sent = in_array('SENT', $m['labelIds'] ?? [], true);
            $fromCustomer = preg_match('/(?:^|[<\s])'.preg_quote($email, '/').'(?:>|\s|$)/i', $h['from'] ?? '') === 1;
            $automatic = isset($h['auto-submitted']) && strtolower($h['auto-submitted']) !== 'no';
            $messages[] = ['id' => $id, 'message_id' => $h['message-id'] ?? null,
                'kind' => $fromCustomer && ! $automatic ? 'customer_reply' : ($sent ? 'prior_outbound' : 'ambiguous'),
                'thread_id' => $m['threadId'] ?? null, 'occurred_at' => isset($m['internalDate']) ? (int) $m['internalDate'] : null];
        }

        return ['complete' => true, 'messages' => $messages, 'human_takeover' => false, 'checkpoint' => ['message_ids' => $ids]];
    }
}
