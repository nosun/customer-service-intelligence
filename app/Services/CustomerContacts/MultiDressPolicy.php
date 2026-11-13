<?php

namespace App\Services\CustomerContacts;

use InvalidArgumentException;

class MultiDressPolicy
{
    public function identity(array $s): array
    {
        $i = $s['identity'] ?? [];
        $site = config('customer_contacts.sites.'.($i['site_key'] ?? ''));
        if (($i['console_key'] ?? '') !== 'seawave' || ! is_array($site)
            || (int) ($i['sid'] ?? 0) !== (int) $site['sid'] || (int) ($i['oid'] ?? 0) < 1
            || ! preg_match('/\A'.preg_quote($site['prefix'], '/').'\d{8}-\d+\z/', $i['order_number'] ?? '')) {
            throw new InvalidArgumentException('Invalid Seawave site/order identity.');
        }

        return $i;
    }

    public function dresses(array $s): array
    {
        return array_values(array_filter($s['items'] ?? [], fn ($i) => ($i['type'] ?? '') === 'dress' && ($i['active'] ?? false) === true));
    }

    public function count(array $s): int
    {
        return array_sum(array_column($this->dresses($s), 'quantity'));
    }

    public function assess(array $s, array $c): array
    {
        $this->identity($s);
        $result = fn ($d, $r) => ['decision' => $d, 'reason' => $r];
        $contacts = array_values(array_filter($s['contacts'] ?? [], fn ($x) => ($x['source'] ?? '') === 'multi_dress' && ! ($x['released'] ?? true) && ! ($x['deleted'] ?? true)));
        $order = $s['order'] ?? [];
        if ($contacts === [] || ($order['paid'] ?? false) !== true || ($order['cancelled'] ?? true)
            || ($order['shipped'] ?? true) || ($order['production_started'] ?? null) === true || ($order['on_hold'] ?? false) !== true) {
            return $result('skip', 'already_handled_or_ineligible');
        }
        if ($this->count($s) < 2) {
            return $result('manual', 'item_scope_changed');
        }
        if (($order['production_started'] ?? null) !== false) {
            return $result('manual', 'production_stage_unproven');
        }
        foreach ($this->dresses($s) as $item) {
            foreach (['item_code', 'color', 'size'] as $key) {
                if (! is_string($item[$key] ?? null) || trim($item[$key]) === '' || preg_match('/[\r\n]/', $item[$key])) {
                    return $result('manual', 'item_facts_missing');
                }
            }
            if (! is_int($item['quantity'] ?? null) || $item['quantity'] < 1) {
                return $result('manual', 'item_quantity_unknown');
            }
        }
        if (($s['human_hold'] ?? true) || ($s['console_prior_contact'] ?? false) || ($c['human_takeover'] ?? true) || ($c['prior_contact'] ?? true) || ($c['reply_seen'] ?? true)) {
            return $result('manual', 'human_contact_or_reply');
        }
        foreach (['complete', 'fresh', 'identity_unique'] as $key) {
            if (($c[$key] ?? false) !== true) {
                return $result('manual', 'communication_'.$key.'_unproven');
            }
        }
        if (($c['delivery_failure'] ?? true) || ! ($s['policy']['verified'] ?? false)) {
            return $result('manual', 'delivery_or_policy_unverified');
        }

        return ($s['intent_complete'] ?? false) ? $result('not_needed', 'intent_already_known') : $result('prepare_first_email', 'intent_unknown');
    }
}
