<?php

namespace App\Services\CustomerContacts;

use App\Services\ConsoleNew\ConsoleNewConnections;
use RuntimeException;

class ConsoleContactReader
{
    public function __construct(private readonly ConsoleNewConnections $connections) {}

    public function candidates(int $page, int $pageSize): array
    {
        $r = $this->connections->connection('seawave')->read('customer-contacts', ['page' => $page, 'page_size' => min(100, max(1, $pageSize))]);
        $d = $r['data'];
        $p = $d['pagination'] ?? [];
        if (! isset($p['current_page'], $p['last_page']) || (int) $p['current_page'] !== $page || ! is_array($d['items'] ?? null)) {
            throw new RuntimeException('Contact pagination cannot be verified.');
        }

        return ['items' => $d['items'], 'pagination' => $p, 'complete' => $page >= (int) $p['last_page'], 'request_id' => $r['request_id']];
    }

    public function snapshot(string $orderNumber): array
    {
        $client = $this->connections->forContext(null, $orderNumber);
        if ($client->source()['connection'] !== 'seawave') {
            throw new RuntimeException('Only Seawave contacts are supported.');
        }
        $raw = $client->read('order', [], $orderNumber);
        $order = $raw['data'];
        $siteKey = collect(config('customer_contacts.sites'))->keys()->first(fn ($key) => (int) config("customer_contacts.sites.$key.sid") === (int) data_get($order, 'site.id'));
        if (! $siteKey || ($order['order_no'] ?? '') !== $orderNumber) {
            throw new RuntimeException('Order identity does not match the requested site.');
        }
        $contacts = $this->all('customer-contacts', ['number' => $orderNumber, 'sid' => $order['site']['id'], 'archive' => true]);
        $histories = [];
        foreach ($contacts as $c) {
            if ((int) ($c['oid'] ?? 0) !== (int) $order['id']) {
                throw new RuntimeException('Contact order mismatch.');
            }
            $histories = [...$histories, ...$this->all('customer-contact-histories', [], (string) $c['id'])];
        }
        $workOrders = $this->all('work-orders', ['order_no' => $orderNumber]);
        $productionKnown = array_key_exists('production_started', $order);
        $productionStarted = $order['production_started'] ?? null;
        // Only an explicit boolean proves no production. Undocumented work-order status codes cannot prove it.
        $items = array_map(fn ($i) => ['id' => $i['id'], 'type' => $i['type'] ?? null,
            'active' => ($i['status'] ?? null) === 1, 'item_code' => $i['item_code'] ?? '',
            'color' => $i['color'] ?? '', 'size' => $i['size'] ?? '', 'quantity' => $i['quantity'] ?? null], $order['items'] ?? []);
        $s = [
            'schema_version' => 'multi_dress.first_round.input.v1',
            'identity' => ['console_key' => 'seawave', 'site_key' => $siteKey, 'sid' => $order['site']['id'], 'oid' => $order['id'], 'order_number' => $orderNumber],
            'customer' => ['email' => $order['customer']['email'] ?? ''],
            'order' => ['paid' => data_get($order, 'status.payment_status') === 1,
                'cancelled' => data_get($order, 'status.order_status') === -1,
                'shipped' => data_get($order, 'status.shipping_status') !== 0,
                'production_started' => $productionKnown ? $productionStarted : null,
                'on_hold' => data_get($order, 'status.order_status') === 0 && collect($contacts)->contains(fn ($c) => ($c['blocks_assignment'] ?? false) && empty($c['released_at']) && empty($c['deleted_at']))],
            'items' => $items, 'order_notes' => $order['notes'] ?? [],
            'contacts' => array_map(fn ($c) => [...$c, 'released' => ! empty($c['released_at']), 'deleted' => ! empty($c['deleted_at'])], $contacts),
            'histories' => $histories, 'work_orders' => $workOrders,
            'trigger' => ['verified' => false, 'original_count' => null, 'item_ids' => [], 'rule_version' => null],
            'human_hold' => false, 'intent_complete' => false,
            'other_blockers' => array_values(array_filter($contacts, fn ($c) => ($c['source'] ?? '') !== 'multi_dress'
                && empty($c['released_at']) && empty($c['deleted_at']))),
            'policy' => ['verified' => (bool) config("customer_contacts.sites.$siteKey.policy_verified"),
                'version' => config("customer_contacts.sites.$siteKey.policy_version"), 'url' => config("customer_contacts.sites.$siteKey.policy_url")],
        ];
        // Console changes by a colleague are communication evidence, not AI instructions.
        $s['console_prior_contact'] = collect($contacts)->contains(fn ($c) => filled($c['internal_note'] ?? null) || filled($c['customer_requirement'] ?? null) || ($c['status'] ?? 'customer') !== 'customer');

        return $s;
    }

    public function contact(int $id): array
    {
        return $this->connections->connection('seawave')->read('customer-contact', [], (string) $id)['data'];
    }

    private function all(string $resource, array $query, ?string $id = null): array
    {
        $items = [];
        for ($page = 1; $page <= config('customer_contacts.page_limit'); $page++) {
            $data = $this->connections->connection('seawave')->read($resource, [...$query, 'page' => $page, 'page_size' => 100], $id)['data'];
            $p = $data['pagination'] ?? [];
            if (! isset($p['current_page'], $p['last_page']) || (int) $p['current_page'] !== $page || ! is_array($data['items'] ?? null)) {
                throw new RuntimeException('Incomplete Console pagination.');
            }
            $items = [...$items, ...$data['items']];
            if ($page >= (int) $p['last_page']) {
                return $items;
            }
        }
        throw new RuntimeException('Console page limit reached; snapshot is incomplete.');
    }
}
