<?php

namespace App\Services\TicketWork;

use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Services\ConsoleNew\ConsoleNewClient;
use App\Services\Work\FactResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

class TicketFactResolver
{
    public function __construct(
        private readonly ConsoleNewClient $console,
        private readonly TicketRuntimeContext $context,
    ) {}

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

    /** @return list<string> */
    public function orderNumbers(Conversation $conversation, ConversationEvent $trigger): array
    {
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
