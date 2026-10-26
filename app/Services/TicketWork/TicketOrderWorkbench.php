<?php

namespace App\Services\TicketWork;

use App\Models\BusinessContextSnapshot;
use App\Models\CommerceSite;
use App\Models\Conversation;
use App\Services\Cancellations\CancellationCaseVerifier;
use App\Services\ConsoleNew\ConsoleNewClient;
use App\Services\Knowledge\KnowledgeResolver;
use App\Services\TradeManager\ProductClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

class TicketOrderWorkbench
{
    public function __construct(
        private readonly ConsoleNewClient $client,
        private readonly CancellationCaseVerifier $orders,
        private readonly KnowledgeResolver $knowledge,
        private readonly ProductClient $products,
    ) {}

    public function inputHash(Conversation $ticket): string
    {
        return hash('sha256', json_encode([
            $ticket->site_key, $ticket->requester_email, $ticket->status, $ticket->silo,
            $ticket->linked_order_number, $ticket->draftGuidance(),
            $ticket->events()->orderBy('id')->get(['id', 'content_hash', 'body_text', 'is_private', 'author_type', 'occurred_at'])->toArray(),
            $this->knowledge->available('ticket', $ticket->site_key)->map(fn ($p) => [$p->id, $p->version, $p->updated_at, $p->bindings->toArray()])->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /** Only identity and order selection affect the freshness of the fetched facts. */
    public function factInputHash(Conversation $ticket): string
    {
        return hash('sha256', json_encode([
            $ticket->site_key, $ticket->requester_email, $ticket->linked_order_number,
        ], JSON_THROW_ON_ERROR));
    }

    public function inspect(Conversation $ticket, ?string $specifiedOrder = null, ?int $actor = null): BusinessContextSnapshot
    {
        $summary = ['input_hash' => $this->inputHash($ticket), 'fact_input_hash' => $this->factInputHash($ticket), 'order_candidates' => []];
        $number = mb_strtolower(trim((string) ($specifiedOrder ?? $ticket->linked_order_number)));
        try {
            if ($number === '') {
                $numbers = $this->orders->extractOrderNumbers($ticket);
                if ($numbers === [] && filled($ticket->requester_email)) {
                    $candidates = $this->pages(fn ($page) => $this->client->findOrdersByEmail($ticket->requester_email, 100, $page));
                    $numbers = array_values(array_unique(array_filter(array_column($candidates, 'order_no'))));
                }
                $summary['order_candidates'] = $numbers;
                if (count($numbers) !== 1) {
                    throw new RuntimeException($numbers === [] ? '未查到订单，请输入订单号。' : '存在多个候选订单，请选择具体订单后重新核查。');
                }
                $number = mb_strtolower($numbers[0]);
            }
            if (! preg_match('/\A[a-z]{2,12}\d{8}-\d{3,12}\z/i', $number)) {
                throw new RuntimeException('订单号格式不正确。');
            }
            $result = $this->client->findOrder($number);
            $order = $result['data'];
            if ($result['status'] !== 'found' || ! is_array($order)) {
                throw new RuntimeException('订单不存在。');
            }
            if (strcasecmp((string) ($order['order_no'] ?? ''), $number) !== 0) {
                throw new RuntimeException('接口返回的订单号不匹配。');
            }
            $email = mb_strtolower(trim((string) data_get($order, 'customer.email')));
            if ($email === '' || $email !== mb_strtolower(trim((string) $ticket->requester_email))) {
                throw new RuntimeException('订单邮箱与 Ticket 请求人不匹配，需人工核实身份。');
            }
            $resolvedSite = null;
            if (blank($ticket->site_key)) {
                $host = $this->siteHost((string) data_get($order, 'site.url'));
                $sites = CommerceSite::where('enabled', true)->get()->filter(fn ($site) => $host !== '' && $this->siteHost((string) $site->public_base_url) === $host);
                if ($sites->count() !== 1) {
                    throw new RuntimeException('Ticket 站点未识别，且订单域名无法唯一匹配已配置站点，请人工核实。');
                }
                $resolvedSite = $host;
            } elseif (! $this->siteMatches($ticket, $order)) {
                throw new RuntimeException('订单站点与 Ticket 站点不匹配，请人工核实。');
            }
            $order = Arr::only($order, ['order_no', 'site', 'status', 'amounts', 'time', 'payment_method', 'payment_channel', 'currency', 'shipping', 'packages', 'items', 'refunds']);
            $order['items'] = collect($order['items'] ?? [])->map(fn ($item) => [...Arr::only($item, ['id', 'product_sn', 'item_code', 'type', 'status', 'status_text', 'product_name', 'size', 'quantity', 'unit_price', 'total_amount', 'custom_measurements']), 'status' => isset($item['status']) && is_numeric($item['status']) ? (int) $item['status'] : null])->all();
            $order['refunds'] = isset($order['refunds']) ? collect($order['refunds'])->map(fn ($refund) => Arr::only($refund, ['id', 'amount', 'currency', 'refund_type', 'refund_status', 'refund_status_text', 'payment_gateway', 'created_at', 'refund_at']))->all() : null;
            $work = $this->pages(fn ($page) => $this->client->workOrders($number, 100, $page));
            $work = array_map(fn ($item) => Arr::only($item, ['id', 'work_order_no', 'order_no', 'product_sn', 'item_code', 'size', 'work_process', 'work_process_text', 'product_status', 'product_status_text']), $work);
            if (collect($work)->contains(fn ($w) => strcasecmp((string) ($w['order_no'] ?? ''), $number) !== 0)) {
                throw new RuntimeException('工单返回了其他订单的数据。');
            }
            if ($resolvedSite !== null || $ticket->linked_order_number !== $number) {
                $ticket->update([
                    'site_key' => $resolvedSite ?? $ticket->site_key,
                    'linked_order_number' => $number, 'order_linked_by' => $actor,
                ]);
            }
            $summary = [...$summary, 'input_hash' => $this->inputHash($ticket), 'fact_input_hash' => $this->factInputHash($ticket), 'order' => $order, 'work_orders' => $work];
            $summary['fact_hash'] = $this->factHash($summary);
            $summary['assessment'] = $this->assess($ticket, $summary);

            return $this->record($ticket, $number, 'found', $summary, $result['request_id']);
        } catch (Throwable $e) {
            if ($e instanceof QueryException) {
                throw $e;
            }

            return $this->record($ticket, $number, 'failed', $summary, error: get_class($e) === RuntimeException::class ? mb_substr($e->getMessage(), 0, 300) : '业务接口查询失败，请检查接口权限或连接后重试。');
        }
    }

    public function isFresh(Conversation $ticket, BusinessContextSnapshot $snapshot): bool
    {
        return $snapshot->lookup_status === 'found' && $snapshot->queried_at->gt(now()->subMinutes(10))
            && hash_equals((string) data_get($snapshot->summary, 'fact_input_hash'), $this->factInputHash($ticket));
    }

    public function factHash(array $summary): string
    {
        return hash('sha256', json_encode(Arr::only($summary, ['order', 'work_orders']), JSON_THROW_ON_ERROR));
    }

    public function policies(Conversation $ticket, string $intent): array
    {
        return $this->knowledge->forIntents('ticket', $ticket->site_key, [$intent])->filter(fn ($p) => $p->knowledge_type === 'policy' && $p->bindings->contains('intent_key', $intent))->map(fn ($p) => [
            'id' => $p->id, 'key' => $p->key, 'version' => $p->version, 'title' => $p->title,
            'rules' => $p->policy_rules,
        ])->all();
    }

    public function assess(Conversation $ticket, array $summary): array
    {
        $order = $summary['order'];
        $work = $summary['work_orders'];
        $blocked = [];
        if ($ticket->silo !== 'tickets' || ! in_array($ticket->status, ['open', 'pending', 'onhold'], true)) {
            $blocked[] = 'Ticket 已不在当前待处理工作区。';
        }
        if (data_get($order, 'status.shipping_status') !== 0 || data_get($order, 'shipping.shipped_at') || collect($order['packages'] ?? [])->contains(fn ($p) => ($p['shipping_status'] ?? null) !== 0)) {
            $blocked[] = '已发货或包裹发货状态不完整。';
        }
        if ($work === [] || collect($work)->contains(fn ($w) => ($w['work_process'] ?? null) !== 0 || ($w['product_status'] ?? null) !== 0)) {
            $blocked[] = '工单缺失、已开工或状态不允许直接修改；请先核实实际制作状态。';
        }
        if (! isset($order['status']['order_status']) || in_array($order['status']['order_status'], [-2, -1, 2], true)) {
            $blocked[] = '订单当前状态不允许直接处理。';
        }
        if (empty($order['items']) || ! is_array($order['packages'] ?? null) || ! isset($order['amounts']['pay_amount'])) {
            $blocked[] = '商品、包裹或付款事实不完整。';
        }
        if (collect($order['items'] ?? [])->contains(fn ($item) => ! isset($item['id']) || ! isset($item['status']))) {
            $blocked[] = '商品身份或状态不完整。';
        }
        if (! is_array($order['refunds'] ?? null)) {
            $blocked[] = '退款记录不完整。';
        }
        $cancelPolicies = $this->policies($ticket, 'production_cancellation');
        $sizePolicies = $this->policies($ticket, 'standard_size_change');

        return [
            'order_cancelled' => data_get($order, 'status.order_status') === -1,
            'blocking_reasons' => $blocked,
            'cancellation' => [
                'technical_ready' => $blocked === [],
                'policies' => $cancelPolicies,
                'next_step' => $cancelPolicies === [] ? '缺少适用的已核准取消政策，需人工审核。' : '核对客户整单取消意愿与政策资格；待生产不代表工厂实际尚未制作。',
            ],
            'size_change' => ['technical_ready' => $blocked === [], 'policies' => $sizePolicies, 'next_step' => '明确具体商品和目标标准尺码；20 与 20W 不可互换。'],
            'refund' => $this->refundAdvice($order),
            'notification' => '客户通知仍在 HelpDesk 发送；请结合消息时间线核查是否已通知。',
        ];
    }

    public function standardSize(Conversation $ticket, array $item, string $size): array
    {
        if (! preg_match('/\A\d{1,2}W?\z/', $size) || ! preg_match('/\A\d{1,2}W?\z/', (string) ($item['size'] ?? '')) || ($item['type'] ?? null) !== 'dress') {
            throw new RuntimeException('仅支持礼服的标准尺码，定制尺寸需人工处理。');
        }
        if (str_ends_with((string) ($item['size'] ?? ''), 'W') !== str_ends_with($size, 'W')) {
            throw new RuntimeException('原尺码与目标尺码的 W 系列不同，请先取得客户明确确认，再到订单后台人工处理。');
        }
        $site = $this->commerceSite($ticket);
        if ($site === null || ! $site->enabled || ! filled($site->admin_api_url) || ! filled($site->api_token)) {
            throw new RuntimeException('站点商品接口未配置，无法核验标准尺码表。');
        }
        $options = $this->products->options($site, (string) $item['product_sn']);
        $chart = $options['size_chart'][$size] ?? null;
        if (! is_array($chart) || collect(['bust', 'waist', 'hips'])->contains(fn ($k) => ! is_numeric($chart[$k] ?? null) || (float) $chart[$k] <= 0)) {
            throw new RuntimeException('目标标准尺码的测量表缺失或不完整，不能只修改尺码名称。');
        }

        return collect($chart)->only(['bust', 'waist', 'hips', 'hollow_to_floor', 'hollow_to_hem', 'height', 'length', 'armhole', 'arm_girth', 'arm_length', 'sleeve_length'])->mapWithKeys(fn ($v, $k) => [ucwords(str_replace('_', ' ', $k)) => $v])->all();
    }

    private function refundAdvice(array $order): array
    {
        $records = $order['refunds'] ?? null;
        $paid = data_get($order, 'amounts.pay_amount');
        $currencies = collect($records ?? [])->pluck('currency')->filter()->unique()->values();
        $remaining = null;
        if (is_array($records) && is_numeric($paid) && (float) $paid >= 0
            && ($records === [] || (filled($order['currency'] ?? null) && $currencies->count() === 1 && $currencies->first() === $order['currency']))
            && collect($records)->every(fn ($r) => is_numeric($r['amount'] ?? null) && (float) $r['amount'] >= 0 && filled($r['currency'] ?? null) && in_array($r['refund_status'] ?? null, [0, 1], true))) {
            $remaining = max(0, $this->cents($paid) - collect($records)->sum(fn ($r) => $this->cents($r['amount'])));
        }

        return [
            'paid' => $paid, 'currency' => $order['currency'] ?? null,
            'record_currencies' => $currencies->all(),
            'unallocated_paid_amount' => $remaining === null ? null : number_format($remaining / 100, 2, '.', ''),
            'calculation' => '实付减去全部已退及待退记录，仅为未分配金额上限，不代表政策允许的退款金额；币种须核实一致。',
            'records' => $records,
            'next_step' => data_get($order, 'status.order_status') === -1 && $records === [] ? '订单已取消，未查到退款记录：退款待人工处理。' : '逐条核对退款状态、原支付方式及适用政策，不创建退款。',
            'unknowns' => ['支付渠道实际结算与到账', '银行争议及临时入账', '分期计划调整', '运费及保护费是否可退'],
        ];
    }

    private function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function commerceSite(Conversation $ticket): ?CommerceSite
    {
        return CommerceSite::whereIn('site_key', array_unique([(string) $ticket->site_key, explode('.', (string) $ticket->site_key)[0]]))->first();
    }

    private function siteHost(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }

    private function siteMatches(Conversation $ticket, array $order): bool
    {
        $key = strtolower(trim((string) $ticket->site_key));
        $host = strtolower((string) parse_url((string) data_get($order, 'site.url'), PHP_URL_HOST));
        $expected = strtolower((string) parse_url((string) $this->commerceSite($ticket)?->public_base_url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        $expected = preg_replace('/^www\./', '', $expected);

        return $key !== '' && $host !== '' && ($host === $key || ($expected !== '' && $host === $expected));
    }

    /** Fail closed on missing pagination metadata or an incomplete response. */
    private function pages(callable $fetch): array
    {
        $all = [];
        $seen = [];
        for ($page = 1; $page <= 50; $page++) {
            $response = $fetch($page);
            $pagination = $response['pagination'];
            if (($pagination['current_page'] ?? null) !== $page || ! is_int($pagination['last_page'] ?? null) || ! is_int($pagination['total'] ?? null)) {
                throw new RuntimeException('接口分页信息不完整。');
            }
            foreach ($response['items'] as $item) {
                $key = $item['id'] ?? $item['order_no'] ?? null;
                if ($key === null || isset($seen[(string) $key])) {
                    throw new RuntimeException('接口分页记录缺少标识或出现重复，请重新核查。');
                }
                $seen[(string) $key] = true;
            }
            array_push($all, ...$response['items']);
            if ($page >= $pagination['last_page']) {
                if (count($all) !== $pagination['total']) {
                    throw new RuntimeException('接口记录数量不完整，请重新核查。');
                }

                return $all;
            }
        }
        throw new RuntimeException('接口超过分页上限，需人工核查。');
    }

    private function record(Conversation $ticket, string $number, string $status, array $summary, ?string $requestId = null, ?string $error = null): BusinessContextSnapshot
    {
        return $ticket->businessContextSnapshots()->create([
            'source' => 'console_new', 'context_type' => 'ticket_order', 'lookup_key' => $number ?: null,
            'lookup_status' => $status, 'summary' => $summary, 'queried_at' => now(),
            'source_request_id' => $requestId, 'response_hash' => $summary['fact_hash'] ?? null,
            'error_code' => $error === null ? null : 'REVIEW_REQUIRED', 'error_message' => $error,
        ]);
    }
}
