@php
    $metricLabels = [
        'prediction_coverage' => '预测覆盖率', 'quality_review_coverage' => '人工审核覆盖率',
        'workflow_failure_rate' => 'Workflow 失败率', 'missed_intent_rate' => '漏意图率',
        'false_block_rate' => '错误阻止率', 'missed_required_block_rate' => '错误放行率',
        'fact_error_rate' => '事实错误率', 'policy_violation_rate' => 'Policy 违规率',
        'product_identity_repetition_rate' => '商品标识复述率',
        'unsupported_follow_up_action_rate' => '虚构后续动作率',
    ];
@endphp

<a href="{{ admin_url('workflow-replay-reviews') }}"><i class="fa fa-arrow-left"></i> 返回回放批次</a>

<div class="row" style="margin-top:12px">
    <div class="col-md-8">
        <div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">人工审核进度</h3></div><div class="box-body">
            <div class="progress"><div class="progress-bar" style="width:{{ $run->case_count ? ($run->reviewed_count / $run->case_count * 100) : 0 }}%">{{ $run->reviewed_count }}/{{ $run->case_count }}</div></div>
            <a class="btn btn-default btn-sm" href="{{ admin_url('workflow-replay-reviews/'.$run->id) }}">全部</a>
            <a class="btn btn-warning btn-sm" href="{{ admin_url('workflow-replay-reviews/'.$run->id.'?review=pending') }}">待审核</a>
            <a class="btn btn-danger btn-sm" href="{{ admin_url('workflow-replay-reviews/'.$run->id.'?review=issues') }}">有问题</a>
        </div></div>

        @foreach($cases as $case)
            @php($prediction = $case->prediction)
            <div class="box box-{{ $case->isReviewed() ? (($case->fact_errors || $case->policy_violations) ? 'danger' : 'success') : 'warning' }}">
                <div class="box-header with-border"><h3 class="box-title">{{ $case->case_key }} · {{ $case->category ?: '未分类' }}</h3><span class="pull-right">{{ $case->isReviewed() ? '已审核' : '待审核' }}</span></div>
                <div class="box-body">
                    <p><strong>客户问题：</strong>{{ $case->question }}</p>
                    <p><strong>期望意图：</strong>{{ implode('、', $case->expected_intents ?: []) ?: '—' }} · <strong>应阻止：</strong>{{ $case->must_block ? '是' : '否' }}</p>
                    <p><strong>识别意图：</strong>{{ implode('、', data_get($prediction, 'intents', [])) ?: '—' }} · <strong>结果：</strong>{{ data_get($prediction, 'status', '—') }} · <strong>Execution：</strong>{{ data_get($prediction, 'execution_id', '—') }}</p>
                    <div class="well" style="white-space:pre-wrap">{{ data_get($prediction, 'answer_localized') ?: data_get($prediction, 'answer_en', '—') }}</div>
                    @if(data_get($prediction, 'missing_facts'))<p class="text-warning"><strong>缺失事实：</strong>{{ implode('；', data_get($prediction, 'missing_facts')) }}</p>@endif
                    @if(data_get($prediction, 'safety_flags'))<p class="text-warning"><strong>安全提示：</strong>{{ implode('；', data_get($prediction, 'safety_flags')) }}</p>@endif
                    <details><summary>查看事实和 Policy</summary>
                        <h5>事实</h5><pre style="max-height:360px;overflow:auto">{{ json_encode(data_get($prediction, 'facts', []), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        <h5>Policy IDs</h5><p>{{ implode('、', data_get($prediction, 'knowledge_article_ids', [])) ?: '无' }}</p>
                    </details>
                </div>
                <form method="post" action="{{ admin_url('workflow-replay-reviews/'.$run->id.'/cases/'.$case->id) }}"><div class="box-footer">
                    @csrf
                    <div class="row">
                        <div class="col-md-2 form-group"><label>事实错误数</label><input class="form-control" name="fact_errors" type="number" min="0" value="{{ old('fact_errors', $case->fact_errors) }}" required></div>
                        <div class="col-md-2 form-group"><label>Policy 违规数</label><input class="form-control" name="policy_violations" type="number" min="0" value="{{ old('policy_violations', $case->policy_violations) }}" required></div>
                        <div class="col-md-2 form-group"><label>原样采用</label><select class="form-control" name="accepted_as_is"><option value="">未判断</option><option value="1" @selected($case->accepted_as_is === true)>是</option><option value="0" @selected($case->accepted_as_is === false)>否</option></select></div>
                        <div class="col-md-4 form-group"><label>审核备注</label><input class="form-control" name="review_notes" value="{{ old('review_notes', $case->review_notes) }}"></div>
                        <div class="col-md-2 form-group"><label>&nbsp;</label><button class="btn btn-primary form-control">保存审核</button></div>
                    </div>
                </div></form>
            </div>
        @endforeach
        @if($cases->hasPages())<div>{{ $cases->links() }}</div>@endif
    </div>
    <div class="col-md-4">
        <div class="box box-solid"><div class="box-header with-border"><h3 class="box-title">发布门禁</h3></div><div class="box-body">
            <p>状态：<strong>{{ $run->status }}</strong></p>
            <p>Definition checksum：<code>{{ $run->definition_checksum }}</code></p>
            <p>Suite checksum：<code>{{ $run->suite_checksum }}</code></p>
            @if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @if($run->release_report)
                <div class="alert alert-{{ data_get($run->release_report, 'passed') ? 'success' : 'danger' }}">{{ data_get($run->release_report, 'passed') ? '门禁通过' : '门禁未通过' }}</div>
                <dl>@foreach(($run->metrics ?: []) as $key => $value)@if(isset($metricLabels[$key]))<dt>{{ $metricLabels[$key] }}</dt><dd>{{ is_float($value) ? number_format($value * 100, 2).'%' : $value }}</dd>@endif @endforeach</dl>
                @if(data_get($run->release_report, 'failures'))<ul class="text-danger">@foreach(data_get($run->release_report, 'failures') as $failure)<li>{{ $failure }}</li>@endforeach</ul>@endif
                <p class="text-muted">报告：{{ $run->release_report_path }}</p>
            @endif
        </div><div class="box-footer"><form method="post" action="{{ admin_url('workflow-replay-reviews/'.$run->id.'/gate') }}">@csrf<button class="btn btn-success" @disabled($run->reviewed_count !== $run->case_count)>运行发布门禁</button></form></div></div>
    </div>
</div>
