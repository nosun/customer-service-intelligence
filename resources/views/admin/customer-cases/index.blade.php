<div class="callout callout-info"><h4>通用客服工作中心</h4><p>Case 是实际处理生命周期；分析中心的 Decision Case 仍只作为研究证据，不会在这里自动执行任何业务动作。</p></div>

<div class="box box-primary"><div class="box-body"><form method="get" class="row">
    <div class="col-md-3 form-group"><label>关键词</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] ?? '' }}"></div>
    <div class="col-md-2 form-group"><label>状态</label><select class="form-control" name="status"><option value="">全部</option>@foreach(['open','waiting','resolved','closed'] as $value)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $value }}</option>@endforeach</select></div>
    <div class="col-md-2 form-group"><label>队列</label><input class="form-control" name="queue" value="{{ $filters['queue'] ?? '' }}"></div>
    <div class="col-md-2 form-group"><label>风险</label><select class="form-control" name="risk_level"><option value="">全部</option>@foreach(['normal','high','critical'] as $value)<option value="{{ $value }}" @selected(($filters['risk_level'] ?? '') === $value)>{{ $value }}</option>@endforeach</select></div>
    <div class="col-md-3 form-group"><label>&nbsp;</label><div><button class="btn btn-primary">查询</button> <a class="btn btn-default" href="{{ admin_url('customer-cases') }}">清空</a></div></div>
</form></div></div>

<div class="box box-solid"><div class="box-body table-responsive no-padding"><table class="table table-hover">
    <thead><tr><th>Case</th><th>类型 / 站点</th><th>状态</th><th>风险 / 优先级</th><th>队列</th><th>SLA</th><th>工作 / 动作</th></tr></thead>
    <tbody>@forelse($cases as $case)<tr>
        <td><a href="{{ admin_url('customer-cases/'.$case->id) }}">{{ $case->title }}</a><div class="text-muted">{{ $case->case_key }}</div></td>
        <td>{{ $case->case_type }}<div class="text-muted">{{ $case->site_key ?: '—' }}</div></td><td>{{ $case->status }}</td><td>{{ $case->risk_level }} / {{ $case->priority }}</td><td>{{ $case->queue ?: '—' }}</td><td>{{ $case->sla_due_at ?: '—' }}</td><td>{{ $case->work_items_count }} / {{ $case->action_proposals_count }}</td>
    </tr>@empty<tr><td colspan="7" class="text-center text-muted" style="padding:40px">尚无生产 Case。新的产品问答 Draft 请求会自动创建并关联 Case。</td></tr>@endforelse</tbody>
</table></div>@if($cases->hasPages())<div class="box-footer">{{ $cases->links() }}</div>@endif</div>
