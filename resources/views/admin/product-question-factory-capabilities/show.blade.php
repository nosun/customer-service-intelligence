@php($statuses = \App\Models\ProductQuestionFactoryCapability::STATUSES)

<a href="{{ admin_url('product-question-factory-capabilities') }}"><i class="fa fa-arrow-left"></i> 返回能力列表</a>
<div class="row" style="margin-top:12px">
    <div class="col-md-7">
        <div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">集中询问内容</h3></div><div class="box-body">
            <p style="white-space:pre-wrap">{{ $capability->factory_question }}</p>
            <dl><dt>能力 Key</dt><dd>{{ $capability->capability_key }}</dd><dt>覆盖问答</dt><dd>{{ $capability->question_count }} 条</dd><dt>覆盖产品</dt><dd>{{ $capability->product_count }} 个</dd><dt>分类置信度</dt><dd>{{ number_format($capability->confidence * 100, 1) }}%</dd></dl>
        </div></div>
        <div class="box box-solid"><div class="box-header with-border"><h3 class="box-title">代表性问题</h3></div><div class="box-body">
            @foreach($capability->representative_questions as $item)<div style="padding:9px 0;border-bottom:1px solid #eee"><strong>QA #{{ $item['external_id'] }} · {{ $item['sn'] ?: '无 SN' }}</strong><div>{{ $item['question'] }}</div></div>@endforeach
        </div></div>
    </div>
    <div class="col-md-5"><div class="box box-warning"><div class="box-header with-border"><h3 class="box-title">记录工厂结论</h3></div>
        <form method="post" action="{{ admin_url('product-question-factory-capabilities/'.$capability->id) }}">@csrf @method('put')
            <div class="box-body">
                <div class="form-group"><label>状态</label><select class="form-control" name="review_status">@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected($capability->review_status === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="form-group"><label>工厂原始回复</label><textarea class="form-control" name="factory_answer" rows="5">{{ $capability->factory_answer }}</textarea></div>
                <div class="form-group"><label>适用范围</label><textarea class="form-control" name="applicability" rows="3">{{ $capability->applicability }}</textarea></div>
                <div class="form-group"><label>例外和限制</label><textarea class="form-control" name="exceptions" rows="3">{{ $capability->exceptions }}</textarea></div>
                <div class="form-group"><label>费用规则</label><input class="form-control" name="fee_policy" value="{{ $capability->fee_policy }}" placeholder="例如：免费 / 按款式报价"></div>
                <div class="form-group"><label>交期影响</label><input class="form-control" name="lead_time_policy" value="{{ $capability->lead_time_policy }}"></div>
                <div class="form-group"><label>审核备注</label><textarea class="form-control" name="review_notes" rows="3">{{ $capability->review_notes }}</textarea></div>
            </div><div class="box-footer"><button class="btn btn-warning">保存工厂结论</button></div>
        </form>
    </div></div>
</div>
