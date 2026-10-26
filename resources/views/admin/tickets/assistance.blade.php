@php($assistance = app(\App\Services\TicketWork\TicketAssistanceView::class)->present($ticket, $latestTicketWorkItem))
<div class="box box-primary" id="ticket-assistance">
    <div class="box-header with-border"><h3 class="box-title">智能处理辅助</h3><span class="pull-right label label-info">{{ $assistance['label'] }}</span></div>
    <div class="box-body">
        @if($assistance['status'] === 'stale')<div class="alert alert-warning">以下是历史结果，消息或依据已有变化，请重新分析。</div>@endif
        <h4>1. 情况分析</h4>
        <p>{{ $assistance['analysis']['summary'] ?: '尚无完整调查结论；下列内容仅展示已取得的依据。' }}</p>
        @foreach($assistance['analysis']['requests'] as $requestUnit)<p><strong>客户诉求：</strong>{{ data_get($requestUnit, 'text') }} <span class="text-muted">（客户陈述）</span></p>@endforeach
        @if($assistance['analysis']['facts'])<details><summary>已取得的事实与来源</summary><ul>@foreach($assistance['analysis']['facts'] as $fact)<li>{{ data_get($fact, 'key') }}：{{ is_scalar(data_get($fact, 'value')) ? var_export(data_get($fact, 'value'), true) : json_encode(data_get($fact, 'value'), JSON_UNESCAPED_UNICODE) }}（{{ data_get($fact, 'source') }} / {{ data_get($fact, 'captured_at') }}）</li>@endforeach</ul></details>@endif
        @if($assistance['analysis']['gaps'])<div class="alert alert-warning"><strong>待确认与缺口：</strong><ul>@foreach($assistance['analysis']['gaps'] as $gap)<li>{{ $gap }}</li>@endforeach</ul></div>@endif
        <h4>2. 处理意见</h4>
        <p>{{ data_get($assistance, 'advice.decision.reason') ?: '尚待调查与复核，不能据此视为已完成处理。' }}</p>
        @foreach($assistance['advice']['units'] as $unit)
            <div class="node-card"><strong>{{ data_get($unit, 'request_id') }}</strong> · {{ data_get($unit, 'business') }} · {{ data_get($unit, 'action_stage') }}
                <ul>@foreach(data_get($unit, 'points', []) as $point)<li>{{ $point }}</li>@endforeach</ul>
                @if(data_get($unit, 'essential_unknowns'))<p>需人工核实：{{ implode('；', $unit['essential_unknowns']) }}</p>@endif
                <details><summary>引用依据</summary>{{ implode('、', data_get($unit, 'evidence_refs', [])) }}</details>
            </div>
        @endforeach
        @if($assistance['advice']['followups'])<p><strong>内部跟进建议（尚未执行）：</strong>{{ implode('；', $assistance['advice']['followups']) }}</p>@endif
        <p><a href="#reply-draft">3. 查看邮件草稿与独立复核结果 ↓</a></p>
        @if(in_array($assistance['draft_status'], ['no_reply', 'needs_review'], true))<p class="text-warning">本轮未产生可用新邮件；请确认回应建议与后续安排。</p>@endif
        <details><summary>最近运行的原始产物与历史</summary>
            @foreach($ticketWorkItems->take(10) as $run)<details><summary>#{{ $run->id }} · {{ $run->status }} · {{ $run->created_at }}</summary><pre style="white-space:pre-wrap">{{ json_encode($run->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>@endforeach
        </details>
        <hr>
        <form method="post" action="{{ admin_url('conversations/'.$ticket->id.'/assistance-feedback') }}">@csrf
            <input type="hidden" name="work_item_id" value="{{ $latestTicketWorkItem?->id }}">
            <div class="row"><div class="col-md-6 form-group"><label>反馈内容</label><select class="form-control" name="target"><option value="analysis">情况分析</option><option value="advice">处理意见</option></select></div>
            <div class="col-md-6 form-group"><label>反馈类型</label><select class="form-control" name="kind"><option value="correction">纠正分析或建议</option><option value="fact">补充个案事实与来源</option><option value="decision">填写当前个案决定</option><option value="accepted">接受当前判断</option><option value="rejected">拒绝当前判断</option></select></div></div>
            <div class="form-group"><textarea class="form-control" rows="3" name="body" required maxlength="4000" placeholder="说明纠正内容及来源。个案决定不代表已执行，也不会自动变成通用政策。"></textarea></div>
            <label><input type="checkbox" name="knowledge_candidate" value="1"> 可作为知识改进候选，仍需审核</label><br>
            <label><input type="checkbox" name="regenerate" value="1" checked> 保存后重新分析</label><br>
            <button class="btn btn-primary">保存反馈</button>
        </form>
        <details style="margin-top:12px"><summary>人工反馈与修改历史</summary>
            @foreach($ticket->assistanceFeedback as $feedback)<div class="node-card">#{{ $feedback->id }} · {{ $feedback->target }} / {{ $feedback->kind }} · 操作人 {{ $feedback->created_by }} · {{ $feedback->created_at }}<p style="white-space:pre-wrap">{{ $feedback->body }}</p>
                @if($feedback->snapshot)<details><summary>修改前后与原始依据</summary><pre style="white-space:pre-wrap">{{ json_encode($feedback->snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></details>@endif
            </div>@endforeach
        </details>
    </div>
</div>
