@php
    $statuses = ['candidate' => '待审核', 'confirmed' => '已确认', 'rejected' => '已驳回'];
    $categories = \App\Models\ProductQuestionAnalysis::CATEGORIES;
@endphp

<div class="callout callout-info">
    <h4>按策略场景审核</h4>
    <p>系统先自动分析历史问答，再把相似问题归纳为少量策略。默认仅显示当前成熟版本（{{ $currentVersion }}），不需要逐条审核历史问答。</p>
    @if(!($filters['all_versions'] ?? false))<p><a href="{{ request()->fullUrlWithQuery(['all_versions' => 1]) }}">显示历史试验批次</a></p>@endif
</div>

<div class="box box-primary"><div class="box-body">
    <form method="get" action="{{ admin_url('product-question-policies') }}" class="row">
        <div class="col-md-3 form-group"><label>站点</label><select class="form-control" name="site_id"><option value="">全部</option>@foreach($sites as $site)<option value="{{ $site->id }}" @selected((string)($filters['site_id'] ?? '') === (string)$site->id)>{{ $site->name }}</option>@endforeach</select></div>
        <div class="col-md-3 form-group"><label>分类</label><select class="form-control" name="category"><option value="">全部</option>@foreach($categories as $value => $label)<option value="{{ $value }}" @selected(($filters['category'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-3 form-group"><label>审核状态</label><select class="form-control" name="review_status"><option value="">全部</option>@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected(($filters['review_status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
        <div class="col-md-3 form-group"><label>&nbsp;</label><div><button class="btn btn-primary">查询</button> <a class="btn btn-default" href="{{ admin_url('product-question-policies') }}">清空</a></div></div>
    </form>
</div></div>

<div class="box box-solid"><div class="box-body table-responsive no-padding"><table class="table table-hover">
    <thead><tr><th>站点 / 批次</th><th>策略场景</th><th>分类</th><th>证据</th><th>风险提示</th><th>置信度</th><th>状态</th></tr></thead>
    <tbody>@forelse($candidates as $candidate)<tr>
        <td>{{ $candidate->site->name }}<div class="text-muted">{{ $candidate->run->batch_key }}</div></td>
        <td><a href="{{ admin_url('product-question-policies/'.$candidate->id) }}">{{ $candidate->title }}</a><div class="text-muted">{{ $candidate->scenario_key }}</div></td>
        <td>{{ $categories[$candidate->category] ?? $candidate->category }}</td>
        <td>{{ count($candidate->evidence_question_ids) }} 条</td>
        <td>{{ count($candidate->conflicts) }} 个冲突 · {{ count($candidate->open_questions) }} 个待定问题</td>
        <td>{{ number_format($candidate->confidence * 100, 1) }}%</td>
        <td>{{ $statuses[$candidate->review_status] ?? $candidate->review_status }}</td>
    </tr>@empty<tr><td colspan="7" class="text-center text-muted" style="padding:40px">尚无策略候选，请先运行研究命令。</td></tr>@endforelse</tbody>
</table></div>@if($candidates->hasPages())<div class="box-footer">{{ $candidates->links() }}</div>@endif</div>
