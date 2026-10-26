@php
    $summary = $orderContext?->summary ?? [];
    $order = $summary['order'] ?? [];
    $assessment = $summary['assessment'] ?? [];
    $canAct = \Encore\Admin\Facades\Admin::user()->isAdministrator() || \Encore\Admin\Facades\Admin::user()->can('ticket-order-actions');
@endphp
<div class="box box-primary" id="order-workbench">
    <div class="box-header with-border"><h3 class="box-title">1. 订单核查与处理建议</h3></div>
    <div class="box-body">
        <form method="post" action="{{ admin_url('conversations/'.$ticket->id.'/inspect-order') }}">
            @csrf
            <div class="form-group"><label>关联订单</label><input class="form-control" name="order_no" value="{{ old('order_no', $ticket->linked_order_number) }}" placeholder="留空从消息或邮箱查找；多笔订单需明确选择"></div>
            <button class="btn btn-primary"><i class="fa fa-search"></i> 核查处理建议</button>
            <span class="text-muted">只读查询订单、工单与退款记录</span>
        </form>
        @if($orderContext)
            <hr>
            <p>查询时间：{{ $orderContext->queried_at->format('Y-m-d H:i:s') }} · 来源：console-new</p>
            @if(!$contextFresh)<div class="alert alert-warning">核查结果已失效或查询失败。生成回复时会自动重新核查；身份或站点核查失败需先解决。</div>@endif
            @if($orderContext->error_message)<div class="alert alert-warning">{{ $orderContext->error_message }}</div>@endif
            @if(!empty($summary['order_candidates']))<p>候选订单：{{ implode('、', $summary['order_candidates']) }}</p>@endif
            @if($orderContext->lookup_status === 'found')
                <dl class="detail-kv">
                    <dt>订单</dt><dd>{{ $order['order_no'] }}</dd>
                    <dt>订单状态</dt><dd>{{ data_get($order, 'status.order_status_text', '未知') }}</dd>
                    <dt>发货状态</dt><dd>{{ data_get($order, 'status.shipping_status_text', '未知') }}</dd>
                    <dt>实付</dt><dd>{{ data_get($order, 'amounts.pay_amount', '未知') }} {{ $order['currency'] ?? '（币种待核实）' }}</dd>
                    <dt>支付方式</dt><dd>{{ $order['payment_method'] ?? '未知' }}</dd>
                </dl>
                <div class="table-responsive"><table class="table table-condensed"><thead><tr><th>商品</th><th>尺码</th><th>状态</th><th>测量值</th></tr></thead><tbody>
                    @foreach($order['items'] ?? [] as $item)
                        <tr><td>#{{ $item['id'] }} {{ $item['product_name'] ?? $item['product_sn'] }}</td><td>{{ $item['size'] ?? '未知' }}</td><td>{{ $item['status_text'] ?? '未知' }}</td><td>{{ json_encode($item['custom_measurements'] ?? [], JSON_UNESCAPED_UNICODE) }}</td></tr>
                    @endforeach
                </tbody></table></div>
                <p><strong>工单事实</strong></p>
                <ul>@forelse($summary['work_orders'] ?? [] as $work)<li>{{ $work['work_order_no'] ?? $work['id'] }} · {{ $work['size'] ?? '未知尺码' }} · {{ $work['work_process_text'] ?? '未知生产进度' }} / {{ $work['product_status_text'] ?? '未知商品状态' }}</li>@empty<li>工单缺失，不能视为尚未生产。</li>@endforelse</ul>
                @foreach($assessment['blocking_reasons'] ?? [] as $reason)<p class="text-warning">{{ $reason }}</p>@endforeach
                @if($assessment['order_cancelled'] ?? false)<div class="alert alert-info">订单已取消，无需重复取消。退款与客户通知分别核查。</div>@endif
                <p>{{ data_get($assessment, 'cancellation.next_step') }}</p>
                <p>{{ data_get($assessment, 'size_change.next_step') }}</p>
                <details><summary>物流及包裹事实</summary><pre style="white-space:pre-wrap">{{ json_encode(['shipping' => $order['shipping'] ?? null, 'packages' => $order['packages'] ?? null], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>
                <hr>
                <p><strong>退款核查（仅建议）</strong></p>
                <p>{{ data_get($assessment, 'refund.next_step') }}</p>
                <p>未分配实付金额上限：{{ data_get($assessment, 'refund.unallocated_paid_amount') ?? '无法计算' }}</p>
                <p class="text-muted">{{ data_get($assessment, 'refund.calculation') }}</p>
                <ul>@foreach($order['refunds'] ?? [] as $refund)<li>#{{ $refund['id'] }} · {{ $refund['currency'] ?? '币种未知' }} {{ $refund['amount'] ?? '金额未知' }} · {{ $refund['refund_status_text'] ?? '状态未知' }}</li>@endforeach</ul>
                <p>待核查：{{ implode('；', data_get($assessment, 'refund.unknowns', [])) }}</p>
                @if($canAct && $contextFresh && empty($assessment['blocking_reasons']))
                    @foreach(['cancel_order' => ['label' => '整单取消', 'policies' => data_get($assessment, 'cancellation.policies', [])], 'standard_size_change' => ['label' => '标准改码', 'policies' => data_get($assessment, 'size_change.policies', [])]] as $type => $settings)
                        <details style="margin-top:15px"><summary>{{ $settings['label'] }}：核查依据与操作预览</summary>
                            @if(empty($settings['policies']))<p class="text-warning">缺少适用的已核准政策，需先在知识库审核并绑定当前场景。</p>@else
                                <form method="post" action="{{ admin_url('conversations/'.$ticket->id.'/preview-action') }}">
                                    @csrf
                                    <input type="hidden" name="action_type" value="{{ $type }}">
                                    @if($type === 'standard_size_change')
                                        <div class="form-group"><label>目标商品</label><select class="form-control" name="item_id" required>@foreach($order['items'] ?? [] as $item)<option value="{{ $item['id'] }}">#{{ $item['id'] }} {{ $item['product_name'] ?? '' }} / {{ $item['size'] ?? '' }}</option>@endforeach</select></div>
                                        <div class="form-group"><label>目标标准尺码</label><input class="form-control" name="size" required placeholder="例如 20W；20 与 20W 不可互换"></div>
                                    @endif
                                    <div class="form-group"><label>适用政策</label><select class="form-control" name="policy_id" required>@foreach($settings['policies'] as $policy)<option value="{{ $policy['id'] }}">{{ $policy['title'] }} v{{ $policy['version'] }}</option>@endforeach</select></div>
                                    @foreach($settings['policies'] as $policy)<details><summary>{{ $policy['title'] }} v{{ $policy['version'] }} 的规则</summary><pre style="white-space:pre-wrap">{{ json_encode($policy['rules'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>@endforeach
                                    <div class="form-group"><label>资格核查依据与操作原因</label><textarea class="form-control" name="reason" maxlength="2000" rows="3" required></textarea></div>
                                    <div class="checkbox"><label><input type="checkbox" name="customer_confirmed" value="1" required> 已核对客户明确意愿、商品及处理范围</label></div>
                                    <div class="checkbox"><label><input type="checkbox" name="policy_confirmed" value="1" required> 已核实政策资格及实际制作情况</label></div>
                                    <button class="btn btn-warning">获取操作预览</button>
                                </form>
                            @endif
                        </details>
                    @endforeach
                @endif
            @endif
        @endif
        @foreach($orderActions as $action)
            <hr><p><strong>{{ $action->action_type === 'cancel_order' ? '整单取消' : '标准改码' }}</strong> · {{ $action->status }} · #{{ $action->id }}</p>
            <p>{{ data_get($action->parameters, 'order_number') }} @if(data_get($action->parameters, 'item_id')) / 商品 #{{ data_get($action->parameters, 'item_id') }}@endif</p>
            <pre style="white-space:pre-wrap">{{ json_encode(['diff' => data_get($action->evidence, 'preview.diff'), 'target_measurements' => data_get($action->parameters, 'expected_measurements'), 'verification' => $action->execution_result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            @if($canAct && $action->status === 'proposed' && data_get($action->evidence, 'preview'))
                @if(config('services.console_new.ticket_actions_enabled'))
                    <form method="post" action="{{ admin_url('conversations/'.$ticket->id.'/actions/'.$action->id.'/execute') }}">@csrf
                        <div class="checkbox"><label><input type="checkbox" name="confirm_execution" value="1" required> 确认执行上述变化；退款和邮件发送另行处理</label></div>
                        <button class="btn btn-danger">确认执行业务操作</button>
                    </form>
                @else<p class="text-muted">执行暂未启用，需完成源系统最终状态校验和受控联调。当前预览未修改订单。</p>@endif
            @endif
            @if($canAct && in_array($action->status, ['executing', 'uncertain', 'partial'], true))<form method="post" action="{{ admin_url('conversations/'.$ticket->id.'/actions/'.$action->id.'/reconcile') }}">@csrf<button class="btn btn-info">只读回读核验</button></form>@endif
        @endforeach
        @if($relatedTickets->isNotEmpty())<hr><p>同订单其他 Ticket：@foreach($relatedTickets as $related)<a href="{{ admin_url('conversations/'.$related->id) }}">{{ $related->external_short_id ?: $related->id }}（{{ $related->status }}）</a> @endforeach</p>@endif
    </div>
</div>
