@php($statuses = \App\Models\ProductQuestionFactoryCapability::STATUSES)

<div class="callout callout-info">
    <h4>按能力向工厂集中确认</h4>
    <p>每张卡汇总同类产品问答。先确认适用范围、例外、费用和交期，避免逐款重复询问。</p>
</div>

<div class="box box-primary"><div class="box-body"><form method="get" class="row">
    <div class="col-md-4 form-group"><label>站点</label><select class="form-control" name="site_id"><option value="">全部</option>@foreach($sites as $site)<option value="{{ $site->id }}" @selected((string)($filters['site_id'] ?? '') === (string)$site->id)>{{ $site->name }}</option>@endforeach</select></div>
    <div class="col-md-4 form-group"><label>状态</label><select class="form-control" name="review_status"><option value="">全部</option>@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected(($filters['review_status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
    <div class="col-md-4 form-group"><label>&nbsp;</label><div><button class="btn btn-primary">查询</button> <a class="btn btn-default" href="{{ admin_url('product-question-factory-capabilities') }}">清空</a></div></div>
</form></div></div>

<div class="box box-solid"><div class="box-body table-responsive no-padding"><table class="table table-hover">
    <thead><tr><th>能力</th><th>覆盖</th><th>建议询问工厂</th><th>分类置信度</th><th>状态</th></tr></thead>
    <tbody>@forelse($capabilities as $capability)<tr>
        <td><a href="{{ admin_url('product-question-factory-capabilities/'.$capability->id) }}">{{ $capability->title }}</a><div class="text-muted">{{ $capability->capability_key }} · {{ $capability->site->name }}</div></td>
        <td>{{ $capability->question_count }} 条问答<div class="text-muted">{{ $capability->product_count }} 个产品</div></td>
        <td style="max-width:650px">{{ $capability->factory_question }}</td>
        <td>{{ number_format($capability->confidence * 100, 1) }}%</td>
        <td>{{ $statuses[$capability->review_status] ?? $capability->review_status }}</td>
    </tr>@empty<tr><td colspan="5" class="text-center text-muted" style="padding:40px">尚未生成工厂能力卡。</td></tr>@endforelse</tbody>
</table></div>@if($capabilities->hasPages())<div class="box-footer">{{ $capabilities->links() }}</div>@endif</div>
