<?php

namespace App\Services\Cancellations;

use App\Models\BusinessContextSnapshot;
use App\Models\Conversation;
use App\Services\ConsoleNew\ConsoleNewApiException;
use App\Services\ConsoleNew\ConsoleNewClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Throwable;

class CancellationCaseVerifier
{
    public function __construct(private readonly ConsoleNewClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /** @return list<string> */
    public function extractOrderNumbers(Conversation $conversation): array
    {
        $texts = [(string) $conversation->subject];
        foreach ($conversation->events()
            ->where('event_type', 'message')
            ->where('is_private', false)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->pluck('body_text') as $body) {
            $texts[] = (string) $body;
        }

        preg_match_all('/\b[a-z]{2,12}\d{8}-\d{3,12}\b/i', implode("\n", $texts), $matches);

        $unique = [];
        foreach ($matches[0] ?? [] as $orderNumber) {
            $unique[strtolower($orderNumber)] = $orderNumber;
        }

        return array_values($unique);
    }

    /** @return array{status: string, outcome: ?string, order_number: ?string, snapshot_id: int, message: string} */
    public function verify(Conversation $conversation, ?string $specifiedOrderNumber = null): array
    {
        $specifiedOrderNumber = trim((string) $specifiedOrderNumber);
        $candidates = $specifiedOrderNumber !== ''
            ? [$specifiedOrderNumber]
            : $this->extractOrderNumbers($conversation);

        if ($candidates === []) {
            return $this->recordLocalFailure($conversation, 'missing_order_number', 'Ticket 中未识别到订单号。');
        }
        if (count($candidates) > 1) {
            return $this->recordLocalFailure(
                $conversation,
                'ambiguous_order_number',
                'Ticket 中识别到多个订单号，请使用 --order-no 明确指定。',
                ['order_candidates' => $candidates],
            );
        }

        $orderNumber = $candidates[0];
        try {
            $result = $this->client->findOrder($orderNumber);
            if ($result['status'] === 'not_found') {
                $snapshot = $this->snapshot($conversation, [
                    'lookup_key' => $orderNumber,
                    'lookup_status' => 'not_found',
                    'outcome' => 'order_not_found',
                    'summary' => ['order_number' => $orderNumber],
                    'response_hash' => $result['response_hash'],
                    'source_request_id' => $result['request_id'],
                ]);

                return $this->result($snapshot, 'console-new 未找到该订单。');
            }

            $summary = $this->summarize($conversation, $result['data']);
            $snapshot = $this->snapshot($conversation, [
                'lookup_key' => $orderNumber,
                'lookup_status' => 'found',
                'outcome' => $summary['assessment']['outcome'],
                'summary' => $summary,
                'response_hash' => $result['response_hash'],
                'source_request_id' => $result['request_id'],
            ]);

            return $this->result($snapshot, '已取得 console-new 订单与退款事实。');
        } catch (ConsoleNewApiException $exception) {
            $snapshot = $this->snapshot($conversation, [
                'lookup_key' => $orderNumber,
                'lookup_status' => 'failed',
                'error_code' => $exception->errorCode,
                'error_message' => $exception->getMessage(),
                'source_request_id' => $exception->requestId,
            ]);

            return $this->result($snapshot, 'console-new 查询失败：'.$exception->getMessage());
        } catch (Throwable $exception) {
            if ($exception instanceof QueryException) {
                throw $exception;
            }
            $snapshot = $this->snapshot($conversation, [
                'lookup_key' => $orderNumber,
                'lookup_status' => 'failed',
                'error_code' => 'REQUEST_FAILED',
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
            ]);

            return $this->result($snapshot, 'console-new 查询失败：'.$exception->getMessage());
        }
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function summarize(Conversation $conversation, array $order): array
    {
        $refunds = collect(Arr::wrap($order['refunds'] ?? []))->map(fn ($refund): array => [
            'id' => data_get($refund, 'id'),
            'amount' => data_get($refund, 'amount'),
            'currency' => data_get($refund, 'currency'),
            'refund_type' => data_get($refund, 'refund_type'),
            'reason' => data_get($refund, 'reason'),
            'refund_status' => data_get($refund, 'refund_status'),
            'refund_status_text' => data_get($refund, 'refund_status_text'),
            'created_at' => data_get($refund, 'created_at'),
            'refund_at' => data_get($refund, 'refund_at'),
        ])->values();
        $status = Arr::only(Arr::wrap($order['status'] ?? []), [
            'order_status', 'order_status_text', 'payment_status', 'payment_status_text',
            'shipping_status', 'shipping_status_text', 'refund_status', 'cancellation_stage',
            'cancellation_reason',
        ]);
        $isCancelled = (int) ($status['order_status'] ?? 0) === -1;
        $completedRefunds = $refunds->where('refund_status', 1)->count();
        $pendingRefunds = $refunds->count() - $completedRefunds;
        $recordedRefund = (float) data_get($order, 'amounts.refund', 0);
        $outcome = match (true) {
            $isCancelled && $pendingRefunds > 0 => 'cancelled_refund_pending',
            $isCancelled && ($completedRefunds > 0 || $recordedRefund > 0) => 'cancelled_refund_recorded',
            $isCancelled => 'cancelled_without_refund_record',
            $completedRefunds > 0 || $recordedRefund > 0 => 'not_cancelled_refund_recorded',
            default => 'not_cancelled',
        };
        $customerEmail = strtolower(trim((string) data_get($order, 'customer.email')));
        $ticketEmail = strtolower(trim((string) $conversation->requester_email));

        return [
            'order_number' => data_get($order, 'order_no'),
            'identity_check' => [
                'email_match' => $customerEmail !== '' && $ticketEmail !== '' ? $customerEmail === $ticketEmail : null,
                'site_match' => $this->siteMatches($conversation->site_key, Arr::wrap($order['site'] ?? [])),
            ],
            'status' => $status,
            'amounts' => Arr::only(Arr::wrap($order['amounts'] ?? []), ['total_amount', 'pay_amount', 'refund']),
            'time' => Arr::only(Arr::wrap($order['time'] ?? []), [
                'created_at', 'payment_time', 'finished_at', 'customer_canceled_at',
            ]),
            'shipping' => Arr::only(Arr::wrap($order['shipping'] ?? []), [
                'carrier', 'tracking_no', 'logistics_status', 'shipped_at', 'delivered_at',
            ]),
            'items' => collect(Arr::wrap($order['items'] ?? []))->map(fn ($item): array => Arr::only(
                Arr::wrap($item),
                ['id', 'product_sn', 'item_code', 'status', 'status_text', 'quantity', 'total_amount'],
            ))->values()->all(),
            'refunds' => $refunds->all(),
            'assessment' => [
                'outcome' => $outcome,
                'order_cancelled' => $isCancelled,
                'refund_records' => $refunds->count(),
                'completed_refund_records' => $completedRefunds,
                'pending_refund_records' => $pendingRefunds,
                'policy_review' => 'manual_review_required',
                'policy_reason' => '取消政策计时起点尚待业务确认，且当前政策快照不能自动追溯历史适用版本。',
                'policy_source' => 'docs/policies/dressafford-cancellation-and-return-policy.md',
            ],
        ];
    }

    /** @param array<string, mixed> $site */
    private function siteMatches(?string $ticketSite, array $site): ?bool
    {
        $ticketSite = strtolower(trim((string) $ticketSite));
        if ($ticketSite === '') {
            return null;
        }
        $haystack = strtolower(implode(' ', array_filter([
            (string) ($site['name'] ?? ''),
            (string) ($site['url'] ?? ''),
        ])));

        return $haystack === '' ? null : str_contains($haystack, $ticketSite);
    }

    /** @param array<string, mixed> $attributes */
    private function snapshot(Conversation $conversation, array $attributes): BusinessContextSnapshot
    {
        return BusinessContextSnapshot::create([
            'conversation_id' => $conversation->id,
            'source' => 'console_new',
            'context_type' => 'cancellation',
            'queried_at' => now(),
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $summary */
    private function recordLocalFailure(
        Conversation $conversation,
        string $code,
        string $message,
        array $summary = [],
    ): array {
        $snapshot = $this->snapshot($conversation, [
            'lookup_status' => 'failed',
            'summary' => $summary,
            'error_code' => $code,
            'error_message' => $message,
        ]);

        return $this->result($snapshot, $message);
    }

    /** @return array{status: string, outcome: ?string, order_number: ?string, snapshot_id: int, message: string} */
    private function result(BusinessContextSnapshot $snapshot, string $message): array
    {
        return [
            'status' => $snapshot->lookup_status,
            'outcome' => $snapshot->outcome,
            'order_number' => $snapshot->lookup_key,
            'snapshot_id' => $snapshot->id,
            'message' => $message,
        ];
    }
}
