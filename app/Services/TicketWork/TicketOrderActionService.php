<?php

namespace App\Services\TicketWork;

use App\Models\ActionProposal;
use App\Models\Conversation;
use App\Services\ConsoleNew\ConsoleNewApiException;
use App\Services\ConsoleNew\ConsoleNewClient;
use App\Services\Work\ActionProposalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class TicketOrderActionService
{
    public function __construct(
        private readonly TicketOrderWorkbench $workbench,
        private readonly ConsoleNewClient $client,
        private readonly TicketRuntimeCaseManager $cases,
        private readonly ActionProposalService $proposals,
    ) {}

    public function preview(Conversation $ticket, string $type, ?int $itemId, ?string $size, int $policyId, string $reason, int $actor): ActionProposal
    {
        if (! in_array($type, ['cancel_order', 'standard_size_change'], true)) {
            throw new RuntimeException('不支持此业务动作。');
        }
        $snapshot = $ticket->latestOrderContext()->first();
        if ($snapshot === null || ! $this->workbench->isFresh($ticket, $snapshot)) {
            throw new RuntimeException('核查结果已失效，请先重新核查处理建议。');
        }
        $summary = $snapshot->summary;
        $reasons = data_get($summary, 'assessment.blocking_reasons', []);
        if ($reasons !== []) {
            throw new RuntimeException(implode(' ', $reasons));
        }
        $intent = $type === 'cancel_order' ? 'production_cancellation' : 'standard_size_change';
        $policy = collect($this->workbench->policies($ticket, $intent))->firstWhere('id', $policyId);
        if ($policy === null || trim($reason) === '') {
            throw new RuntimeException('必须选择适用的已核准政策并记录资格核查依据。');
        }
        $customer = $ticket->events()->where('event_type', 'message')->where('is_private', false)
            ->whereIn('author_type', ['client', 'customer'])->orderByDesc('occurred_at')->orderByDesc('id')->first();
        if ($customer === null) {
            throw new RuntimeException('缺少客户公开请求证据。');
        }
        $measurements = [];
        $item = null;
        if ($type === 'standard_size_change') {
            $item = collect(data_get($summary, 'order.items', []))->firstWhere('id', $itemId);
            if ($item === null || ($item['status'] ?? null) === -1) {
                throw new RuntimeException('目标商品不存在或已取消。');
            }
            if (($item['size'] ?? null) === $size) {
                throw new RuntimeException('商品已经是目标尺码，无需重复修改。');
            }
            $matched = $this->itemWorkOrders($summary['work_orders'], $item);
            if ($matched === []) {
                throw new RuntimeException('无法唯一关联目标商品的工单。');
            }
            $measurements = $this->workbench->standardSize($ticket, $item, (string) $size);
        }
        $changes = $type === 'cancel_order' ? ['order_status' => -1] : ['size' => $size];
        $parameters = [
            'conversation_id' => $ticket->id, 'order_number' => $snapshot->lookup_key,
            'site_key' => $ticket->site_key, 'item_id' => $itemId, 'changes' => $changes,
            'reason' => trim($reason), 'snapshot_id' => $snapshot->id,
            'input_hash' => $summary['input_hash'], 'fact_hash' => $summary['fact_hash'],
            'policy' => $policy, 'expected_measurements' => $measurements, 'item_before' => $item,
        ];
        if ($type === 'cancel_order') {
            $parameters['item_id'] = null;
        }
        $case = $this->cases->forConversation($ticket, $customer->id);
        $proposal = $this->proposals->propose($case, $type, $parameters, ['customer_event_id' => $customer->id], proposedBy: $actor);
        if (data_get($proposal->evidence, 'preview') !== null || $proposal->status !== 'proposed') {
            return $proposal;
        }
        $remote = $this->client->updateOrder($snapshot->lookup_key, [
            'preview' => true, 'changes' => $changes, 'reason' => trim($reason),
        ], $parameters['item_id']);
        $data = $remote['data'];
        if (($data['allowed'] ?? false) !== true || ! is_string($data['confirm_token'] ?? null) || ! filled($data['expires_at'] ?? null)) {
            throw new RuntimeException('订单接口未返回可用的操作预览。');
        }
        $proposal->update(['evidence' => [
            ...$proposal->evidence,
            'preview' => ['diff' => $data['diff'] ?? [], 'expires_at' => Carbon::parse($data['expires_at'], 'Asia/Shanghai')->toIso8601String(), 'request_id' => $remote['request_id'], 'guard_contract' => $data['guard_contract'] ?? null],
            'encrypted_confirm_token' => Crypt::encryptString($data['confirm_token']),
        ]]);

        return $proposal->refresh();
    }

    public function execute(Conversation $ticket, ActionProposal $proposal, int $actor): ActionProposal
    {
        if ((int) data_get($proposal->parameters, 'conversation_id') !== $ticket->id
            || ! in_array($proposal->action_type, ['cancel_order', 'standard_size_change'], true)) {
            throw new RuntimeException('业务动作不属于当前 Ticket。');
        }
        // The source API must atomically validate expected_state before writes are enabled.
        if (! config('services.console_new.ticket_actions_enabled', false)) {
            throw new RuntimeException('取消与改码执行尚未启用：需先完成源系统最终状态校验及受控联调；当前可核查和预览。');
        }
        if (data_get($proposal->evidence, 'preview.guard_contract') !== 'ticket-order-state-v1') {
            throw new RuntimeException('源订单接口尚未确认 ticket-order-state-v1 原子状态校验能力，禁止提交操作。');
        }
        $p = $proposal->parameters;
        $lock = Cache::lock('ticket-order:'.hash('sha256', mb_strtolower($p['order_number'])), 1800);
        if (! $lock->get()) {
            throw new RuntimeException('此订单正在处理，请稍后刷新结果。');
        }
        try {
            $proposal->refresh();
            $ticket->refresh();
            if ($proposal->status !== 'proposed') {
                return $proposal;
            }
            if (! hash_equals($p['input_hash'], $this->workbench->inputHash($ticket))
                || ! data_get($proposal->evidence, 'preview.expires_at')
                || Carbon::parse(data_get($proposal->evidence, 'preview.expires_at'))->isPast()) {
                $proposal->update(['status' => 'stale']);

                return $proposal;
            }
            $snapshot = $this->workbench->inspect($ticket);
            if ($snapshot->lookup_status !== 'found' || ! hash_equals($p['fact_hash'], (string) data_get($snapshot->summary, 'fact_hash'))) {
                $proposal->update(['status' => 'stale']);

                return $proposal;
            }
            $claimed = DB::table('action_proposals')->where('id', $proposal->id)->where('status', 'proposed')->update([
                'status' => 'executing', 'reviewed_by' => $actor, 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            if ($claimed !== 1) {
                return $proposal->refresh();
            }
            try {
                $this->client->updateOrder($p['order_number'], [
                    'preview' => false, 'changes' => $p['changes'], 'reason' => $p['reason'],
                    'confirm_token' => Crypt::decryptString($proposal->evidence['encrypted_confirm_token']),
                    'idempotency_key' => $proposal->idempotency_key,
                    'expected_state' => ['order' => $snapshot->summary['order'], 'work_orders' => $snapshot->summary['work_orders']],
                ], $p['item_id']);
            } catch (ConsoleNewApiException $e) {
                if (in_array($e->statusCode, [401, 403, 404, 409, 422], true)) {
                    $proposal->update(['status' => 'failed', 'execution_result' => ['error_code' => $e->errorCode, 'request_id' => $e->requestId], 'executed_at' => now()]);

                    return $proposal->refresh();
                }
                $proposal->update(['status' => 'uncertain', 'execution_result' => ['error_code' => $e->errorCode], 'executed_at' => now()]);

                return $this->reconcile($ticket, $proposal, $actor);
            } catch (Throwable) {
                $proposal->update(['status' => 'uncertain', 'executed_at' => now()]);

                return $this->reconcile($ticket, $proposal, $actor);
            }

            return $this->reconcile($ticket, $proposal->refresh(), $actor);
        } finally {
            $lock->release();
        }
    }

    /** Recovery reads business state only; it never resubmits a mutation. */
    public function reconcile(Conversation $ticket, ActionProposal $proposal, int $actor): ActionProposal
    {
        if ((int) data_get($proposal->parameters, 'conversation_id') !== $ticket->id
            || ! in_array($proposal->action_type, ['cancel_order', 'standard_size_change'], true)) {
            throw new RuntimeException('业务动作不属于当前 Ticket。');
        }
        if (! in_array($proposal->status, ['executing', 'uncertain', 'partial'], true)) {
            return $proposal;
        }
        $snapshot = $this->workbench->inspect($ticket, $proposal->parameters['order_number']);
        if ($snapshot->lookup_status !== 'found') {
            $proposal->update(['status' => 'uncertain', 'execution_result' => ['snapshot_id' => $snapshot->id, 'verified_by' => $actor], 'executed_at' => now()]);

            return $proposal->refresh();
        }
        $s = $snapshot->summary;
        if ($proposal->action_type === 'cancel_order') {
            $checks = [
                'order' => data_get($s, 'order.status.order_status') === -1,
                'items' => ! empty($s['order']['items']) && collect($s['order']['items'])->every(fn ($item) => ($item['status'] ?? null) === -1),
                'work_orders' => ! empty($s['work_orders']) && collect($s['work_orders'])->every(fn ($w) => ($w['product_status'] ?? null) === -1),
            ];
        } else {
            $p = $proposal->parameters;
            $item = collect($s['order']['items'])->firstWhere('id', $p['item_id']);
            $work = $item === null ? [] : $this->itemWorkOrders($s['work_orders'], $item);
            $checks = [
                'item' => ($item['size'] ?? null) === $p['changes']['size'],
                'measurements' => ! empty($p['expected_measurements']) && collect($p['expected_measurements'])->every(fn ($v, $k) => (string) data_get($item, 'custom_measurements.'.$k) === (string) $v),
                'work_orders' => $work !== [] && collect($work)->every(fn ($w) => ($w['size'] ?? null) === $p['changes']['size']),
            ];
        }
        $status = ! in_array(false, $checks, true) ? 'succeeded' : (in_array(true, $checks, true) ? 'partial' : 'uncertain');
        $proposal->update(['status' => $status, 'execution_result' => [
            'checks' => $checks, 'snapshot_id' => $snapshot->id, 'verified_by' => $actor,
            'verified_at' => now()->toIso8601String(), 'refund_executed' => false, 'notification_sent' => false,
        ], 'executed_at' => now()]);

        return $proposal->refresh();
    }

    private function itemWorkOrders(array $work, array $item): array
    {
        if (empty($item['item_code']) || empty($item['product_sn'])) {
            return [];
        }

        return array_values(array_filter($work, fn ($w) => ($w['item_code'] ?? null) === $item['item_code'] && ($w['product_sn'] ?? null) === $item['product_sn']));
    }
}
