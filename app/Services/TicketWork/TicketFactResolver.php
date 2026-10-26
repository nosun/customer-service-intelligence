<?php

namespace App\Services\TicketWork;

use App\Models\CommerceProduct;
use App\Models\CommerceSite;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Services\ConsoleNew\ConsoleNewClient;
use App\Services\TradeManager\ProductCatalogSyncService;
use App\Services\Work\FactResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

class TicketFactResolver
{
    public function __construct(
        private readonly ConsoleNewClient $console,
        private readonly TicketRuntimeContext $context,
        private readonly TicketOrderWorkbench $workbench,
    ) {}

    public function planningContext(Conversation $conversation): ?array
    {
        $snapshot = $conversation->latestOrderContext()->first();
        if ($snapshot === null || ! $this->workbench->isFresh($conversation, $snapshot)) {
            return null;
        }

        return $this->orderActionFacts($snapshot->summary, 'console-new');
    }

    public function planningFixtureContext(array $fixture): ?array
    {
        if (($fixture['identity_match'] ?? null) !== true || ($fixture['order_found'] ?? true) !== true) {
            return null;
        }

        return is_array($fixture['ticket_order_summary'] ?? null)
            ? $this->investigationOrderFacts($fixture['ticket_order_summary'], 'ticket-replay-fixture')
            : $this->resolveReplayFixture(['intents' => []], $fixture);
    }

    /** @return array{facts:list<array<string,mixed>>,missing_facts:list<string>,safety_flags:list<string>} */
    public function resolve(Conversation $conversation, ConversationEvent $trigger, array $plan, ?array $replayFixture = null): array
    {
        if (($plan['intents'] ?? []) === []) {
            return [
                'facts' => [],
                'missing_facts' => [],
                'safety_flags' => ['No customer request is within the released Ticket workflow scope.'],
            ];
        }
        if (array_intersect($plan['intents'], ['production_cancellation', 'standard_size_change', 'refund_review']) !== []) {
            if ($replayFixture !== null) {
                $summary = $replayFixture['ticket_order_summary'] ?? null;
                if (($replayFixture['identity_match'] ?? null) !== true || ($replayFixture['order_found'] ?? null) !== true) {
                    return $this->missing('order.identity_verified', 'Frozen order-action identity is missing or mismatched.');
                }
                if (! is_array($summary)) {
                    return $this->missing('order.action_snapshot', 'Frozen order-action facts are missing.');
                }
                $resolved = $this->orderActionFacts($summary, 'ticket-replay-fixture');
                $resolved['missing_facts'] = array_values(array_unique([...$resolved['missing_facts'], ...($replayFixture['missing_facts'] ?? [])]));

                return $resolved;
            }
            $snapshot = $conversation->latestOrderContext()->first();
            if ($snapshot === null || ! $this->workbench->isFresh($conversation, $snapshot)) {
                return $this->missing('order.action_snapshot', 'Please refresh the order assessment before drafting cancellation, size-change or refund advice.');
            }

            return $this->orderActionFacts($snapshot->summary, 'console-new');
        }
        $orderNumbers = $this->orderNumbers($conversation, $trigger);
        if ($orderNumbers === []) {
            return $this->missing('order.number', 'No order number was found before the triggering customer message.');
        }
        if (count($orderNumbers) !== 1) {
            return $this->missing('order.disambiguation', 'Multiple order numbers were found; the workflow will not guess which order to use.');
        }
        if ($replayFixture !== null) {
            return $this->resolveReplayFixture($plan, $replayFixture);
        }
        if (! $this->console->isConfigured()) {
            return $this->missing('order.lookup', 'The read-only order Fact provider is not configured.');
        }

        try {
            $orderResult = $this->console->findOrder($orderNumbers[0]);
            if ($orderResult['status'] === 'not_found' || ! is_array($orderResult['data'])) {
                return $this->missing('order.match', 'The order Fact provider did not find the referenced order.');
            }
            $order = $orderResult['data'];
            $capturedAt = now();
            $freshUntil = $capturedAt->copy()->addMinutes(10);
            $status = Arr::wrap($order['status'] ?? []);
            $shipping = Arr::wrap($order['shipping'] ?? []);
            $identityMatch = $this->identityMatches($conversation, $order);
            $facts = [
                $this->fact('order.found', true, $capturedAt, $freshUntil),
                $this->fact('order.identity_match', $identityMatch, $capturedAt, $freshUntil),
                $this->fact('order.status', $status['order_status_text'] ?? $status['order_status'] ?? null, $capturedAt, $freshUntil),
                $this->fact('order.shipping_status', $status['shipping_status_text'] ?? $status['shipping_status'] ?? null, $capturedAt, $freshUntil),
                $this->fact('shipment.carrier', $shipping['carrier'] ?? null, $capturedAt, $freshUntil),
                $this->fact('shipment.tracking_available', trim((string) ($shipping['tracking_no'] ?? '')) !== '', $capturedAt, $freshUntil),
                $this->fact('shipment.logistics_status', $shipping['logistics_status'] ?? null, $capturedAt, $freshUntil),
                $this->fact('shipment.shipped_at', $shipping['shipped_at'] ?? null, $capturedAt, $freshUntil),
                $this->fact('shipment.delivered_at', $shipping['delivered_at'] ?? null, $capturedAt, $freshUntil),
            ];
            $missing = $identityMatch === true ? [] : ['order.identity_verified'];
            if (in_array('logistics_tracking_delivery', $plan['intents'], true)
                && ($status['shipping_status_text'] ?? $status['shipping_status'] ?? null) === null
            ) {
                $missing[] = 'order.shipping_status';
            }
            if (in_array('production_order_status', $plan['intents'], true)) {
                $workOrders = $this->console->workOrders($orderNumbers[0]);
                $statuses = collect($workOrders['items'])->map(fn ($item): array => array_filter([
                    'work_process' => data_get($item, 'work_process_text'),
                    'product_status' => data_get($item, 'product_status_text'),
                ], fn ($value): bool => $value !== null && $value !== ''))->values()->all();
                $facts[] = $this->fact('production.statuses', $statuses, $capturedAt, $freshUntil);
                if ($statuses === []) {
                    $missing[] = 'production.status';
                }
            }

            return [
                'facts' => array_values(array_filter($facts, fn (array $fact): bool => $fact['value'] !== null)),
                'missing_facts' => array_values(array_unique($missing)),
                'safety_flags' => $identityMatch === true
                    ? []
                    : ['The Ticket requester identity is missing or does not match the order; human identity review is required.'],
            ];
        } catch (Throwable $exception) {
            return $this->missing('order.lookup', 'The read-only order Fact provider failed: '.mb_substr($exception->getMessage(), 0, 300));
        }
    }

    /** Read only, with order access limited to requests which actually require an order. */
    public function resolveInvestigation(Conversation $conversation, ConversationEvent $trigger, array $plan, ?array $fixture = null): array
    {
        $orderIntents = array_intersect($plan['intents'], [
            'production_order_status', 'logistics_tracking_delivery', 'production_cancellation',
            'standard_size_change', 'refund_review', 'after_sales_complaint',
        ]);
        if ($orderIntents === []) {
            return ['facts' => [], 'missing_facts' => [], 'safety_flags' => []];
        }
        if ($fixture !== null) {
            if (($fixture['identity_match'] ?? null) !== true || ($fixture['order_found'] ?? true) !== true) {
                return $this->missing('order.identity_verified', 'Frozen order identity is missing or mismatched.');
            }
            if (is_array($fixture['ticket_order_summary'] ?? null)) {
                return $this->investigationOrderFacts($fixture['ticket_order_summary'], 'ticket-replay-fixture');
            }

            return $this->resolveReplayFixture($plan, $fixture);
        }
        $snapshot = $conversation->latestOrderContext()->first();
        if ($snapshot === null || ! $this->workbench->isFresh($conversation, $snapshot)) {
            $snapshot = $this->workbench->inspect($conversation);
        }
        if ($snapshot->lookup_status !== 'found' || ! $this->workbench->isFresh($conversation, $snapshot)) {
            return $this->missing('order.action_snapshot', 'Current identity/site/order verification failed; inspect the workbench.');
        }

        return $this->investigationOrderFacts($snapshot->summary, 'console-new');
    }

    public function resolveProducts(Conversation $conversation, ConversationEvent $trigger, array $plan, ?array $fixture): array
    {
        $result = ['facts' => [], 'missing_facts' => [], 'safety_flags' => []];
        $text = $this->context->stageEvents($conversation, $trigger)->pluck('body_text')->implode("\n")."\n".($conversation->draftGuidance()['text'] ?? '');
        $numbers = collect($plan['clauses'])->whereIn('intent', ['presales_consultation', 'factory_clarification'])
            ->pluck('product_sn')->filter(fn ($sn) => is_string($sn) && $sn !== '')->unique()->values();
        if ($numbers->count() > 3) {
            return ['facts' => [], 'missing_facts' => ['product.scope'], 'safety_flags' => ['At most three explicit products can be investigated in one reply.']];
        }
        foreach ($numbers as $sn) {
            // A model can select a quoted SKU, never invent a product or a provider URL.
            if (! preg_match('/\A[a-zA-Z0-9_-]{3,80}\z/', $sn) || ! preg_match('/(?<![a-zA-Z0-9_-])'.preg_quote($sn, '/').'(?![a-zA-Z0-9_-])/', $text)) {
                $result['missing_facts'][] = 'product.reference';

                continue;
            }
            if ($fixture !== null) {
                $data = $fixture['products'][$sn] ?? null;
                $source = 'ticket-replay-fixture';
            } else {
                $site = CommerceSite::where('enabled', true)->whereIn('site_key', array_unique([(string) $conversation->site_key, explode('.', (string) $conversation->site_key)[0]]))->get();
                if ($site->count() !== 1) {
                    $result['missing_facts'][] = 'product.site';

                    continue;
                }
                $site = $site->first();
                $product = CommerceProduct::where('commerce_site_id', $site->id)->where('sn', $sn)->first();
                if ($product === null || $product->sync_status !== 'completed' || $product->synced_at?->lt(now()->subMinutes(10))) {
                    if (blank($site->admin_api_url) || blank($site->api_token)) {
                        $result['missing_facts'][] = 'product.lookup';

                        continue;
                    }
                    app(ProductCatalogSyncService::class)->sync($site, $sn);
                    $product = CommerceProduct::where('commerce_site_id', $site->id)->where('sn', $sn)->first();
                }
                $data = $product?->sync_status === 'completed' ? [
                    'name' => data_get($product->product, 'name'),
                    'attributes' => data_get($product->product_detail, 'attributes', []),
                    'colors' => data_get($product->options, 'colors', []),
                    'sizes' => data_get($product->options, 'sizes', []),
                    'size_chart' => data_get($product->options, 'size_chart', []),
                    'customizable' => data_get($product->product, 'customizable'),
                ] : null;
                $source = 'tradeManager-product';
            }
            if (! is_array($data)) {
                $result['missing_facts'][] = 'product.lookup';

                continue;
            }
            $at = now();
            $data = Arr::only($data, ['name', 'attributes', 'colors', 'sizes', 'size_chart', 'customizable']);
            $data = json_decode($this->context->redact(json_encode($data, JSON_THROW_ON_ERROR)), true, flags: JSON_THROW_ON_ERROR);
            $result['facts'][] = (new FactResult('product.'.$sn, $data, $source, $at, 'internal', $at->copy()->addMinutes(10)))->toArray();
        }

        return $result;
    }

    private function investigationOrderFacts(array $summary, string $source): array
    {
        $resolved = $this->orderActionFacts($summary, $source);
        if (! is_array($summary['order'] ?? null)) {
            return $resolved;
        }
        $order = $summary['order'];
        $at = now();
        $values = [
            'order.identity_match' => true,
            'order.shipping_status_code' => data_get($order, 'status.shipping_status'),
            'order.amounts' => Arr::only((array) ($order['amounts'] ?? []), ['pay_amount', 'goods_amount', 'shipping_amount', 'shipping_protection_amount']),
            'order.currency' => $order['currency'] ?? null,
            'order.payment_method' => $order['payment_method'] ?? null,
            'order.payment_channel' => $order['payment_channel'] ?? null,
            'shipment.shipped_at' => data_get($order, 'shipping.shipped_at'),
            'shipment.delivered_at' => data_get($order, 'shipping.delivered_at'),
        ];
        foreach ($values as $key => $value) {
            if ($value !== null && (is_scalar($value) || is_array($value))) {
                $resolved['facts'][] = (new FactResult($key, $value, $source, $at, 'internal', $at->copy()->addMinutes(10)))->toArray();
            }
        }

        // Production/refund gaps belong to their claims, rather than blocking every unrelated reply.
        return $resolved;
    }

    /** @return list<string> */
    public function orderNumbers(Conversation $conversation, ConversationEvent $trigger): array
    {
        if (filled($conversation->linked_order_number)) {
            return [$conversation->linked_order_number];
        }
        $text = collect([(string) $conversation->subject])
            ->merge($this->context->stageEvents($conversation, $trigger)->pluck('body_text'))
            ->implode("\n");
        preg_match_all('/\b[a-z]{2,12}\d{8}-\d{3,12}\b/i', $text, $matches);

        return collect($matches[0] ?? [])->map(fn ($number): string => (string) $number)
            ->unique(fn ($number): string => mb_strtolower($number))->values()->all();
    }

    /** @return array<string,mixed> */
    private function fact(string $key, mixed $value, Carbon $capturedAt, Carbon $freshUntil): array
    {
        return (new FactResult($key, $value, 'console-new', $capturedAt, 'internal', $freshUntil))->toArray();
    }

    /** @return array{facts:list<array<string,mixed>>,missing_facts:list<string>,safety_flags:list<string>} */
    private function resolveReplayFixture(array $plan, array $fixture): array
    {
        if (($fixture['lookup_error'] ?? false) === true) {
            return $this->missing('order.lookup', 'The frozen replay fixture represents an order lookup failure.');
        }
        if (($fixture['order_found'] ?? true) !== true) {
            return $this->missing('order.match', 'The frozen replay fixture does not contain a matching order.');
        }

        $capturedAt = now();
        $freshUntil = $capturedAt->copy()->addMinutes(10);
        $identityMatch = array_key_exists('identity_match', $fixture) ? $fixture['identity_match'] : true;
        $facts = [
            $this->replayFact('order.found', true, $capturedAt, $freshUntil),
            $this->replayFact('order.identity_match', $identityMatch, $capturedAt, $freshUntil),
            $this->replayFact('order.status', $fixture['order_status'] ?? null, $capturedAt, $freshUntil),
            $this->replayFact('order.shipping_status', $fixture['shipping_status'] ?? null, $capturedAt, $freshUntil),
            $this->replayFact('shipment.carrier', $fixture['carrier'] ?? null, $capturedAt, $freshUntil),
            $this->replayFact('shipment.tracking_available', $fixture['tracking_available'] ?? false, $capturedAt, $freshUntil),
            $this->replayFact('shipment.logistics_status', $fixture['logistics_status'] ?? null, $capturedAt, $freshUntil),
            $this->replayFact('shipment.shipped_at', $fixture['shipped_at'] ?? null, $capturedAt, $freshUntil),
            $this->replayFact('shipment.delivered_at', $fixture['delivered_at'] ?? null, $capturedAt, $freshUntil),
        ];
        $missing = array_values(array_filter((array) ($fixture['missing_facts'] ?? []), 'is_string'));
        if ($identityMatch !== true) {
            $missing[] = 'order.identity_verified';
        }
        if (in_array('logistics_tracking_delivery', $plan['intents'], true)
            && ! array_key_exists('shipping_status', $fixture)
        ) {
            $missing[] = 'order.shipping_status';
        }
        if (in_array('production_order_status', $plan['intents'], true)) {
            $statuses = array_values(array_filter((array) ($fixture['production_statuses'] ?? []), 'is_array'));
            $facts[] = $this->replayFact('production.statuses', $statuses, $capturedAt, $freshUntil);
            if ($statuses === []) {
                $missing[] = 'production.status';
            }
        }

        return [
            'facts' => array_values(array_filter($facts, fn (array $fact): bool => $fact['value'] !== null)),
            'missing_facts' => array_values(array_unique($missing)),
            'safety_flags' => array_values(array_unique(array_filter([
                ...((array) ($fixture['safety_flags'] ?? [])),
                $identityMatch === true ? null : 'The Ticket requester identity is missing or does not match the order; human identity review is required.',
            ], 'is_string'))),
        ];
    }

    /** @return array<string,mixed> */
    private function replayFact(string $key, mixed $value, Carbon $capturedAt, Carbon $freshUntil): array
    {
        return (new FactResult($key, $value, 'ticket-replay-fixture', $capturedAt, 'internal', $freshUntil))->toArray();
    }

    private function orderActionFacts(array $summary, string $source): array
    {
        if (! is_array($summary['order'] ?? null) || ! is_array($summary['work_orders'] ?? null)) {
            return $this->missing('order.action_snapshot', 'Order action facts are incomplete.');
        }
        $order = $summary['order'];
        $values = [
            'order.status' => data_get($order, 'status.order_status_text'),
            'order.shipping_status' => data_get($order, 'status.shipping_status_text'),
            'order.cancelled' => data_get($order, 'status.order_status') === -1,
            'shipment.carrier' => data_get($order, 'shipping.carrier'),
            'shipment.logistics_status' => data_get($order, 'shipping.logistics_status'),
            'shipment.tracking_available' => data_get($order, 'shipping.tracking_no') !== null ? filled(data_get($order, 'shipping.tracking_no')) : null,
            'order.item_sizes' => collect($order['items'] ?? [])->map(fn ($i) => [
                'product_name' => $this->context->redact((string) ($i['product_name'] ?? '')),
                'size' => $i['size'] ?? null, 'measurements' => $i['custom_measurements'] ?? [],
            ])->all(),
            'production.statuses' => collect($summary['work_orders'])->map(fn ($w) => Arr::only($w, ['work_process_text', 'product_status_text', 'size']))->all(),
            'refund.records' => isset($order['refunds']) ? collect($order['refunds'])->map(fn ($r) => Arr::only($r, ['amount', 'currency', 'refund_status_text']))->all() : null,
            'refund.gateway_verified' => false,
            'business_actions_executed_by_draft_workflow' => false,
        ];
        $at = now();
        $facts = [];
        foreach ($values as $key => $value) {
            if ($value !== null) {
                $facts[] = (new FactResult($key, $value, $source, $at, 'internal', $at->copy()->addMinutes(10)))->toArray();
            }
        }

        $missing = [];
        foreach (['order.status', 'order.shipping_status', 'refund.records'] as $key) {
            if ($values[$key] === null) {
                $missing[] = $key;
            }
        }
        if ($summary['work_orders'] === []) {
            $missing[] = 'production.status';
        }

        return ['facts' => $facts, 'missing_facts' => $missing, 'safety_flags' => []];
    }

    private function identityMatches(Conversation $conversation, array $order): ?bool
    {
        $ticketEmail = mb_strtolower(trim((string) $conversation->requester_email));
        $orderEmail = mb_strtolower(trim((string) data_get($order, 'customer.email')));

        return $ticketEmail === '' || $orderEmail === '' ? null : hash_equals($ticketEmail, $orderEmail);
    }

    /** @return array{facts:list<array<string,mixed>>,missing_facts:list<string>,safety_flags:list<string>} */
    private function missing(string $fact, string $message): array
    {
        return ['facts' => [], 'missing_facts' => [$fact], 'safety_flags' => [$message]];
    }
}
