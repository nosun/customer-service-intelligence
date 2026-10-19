@php
    $arrayFields = \App\Services\ProductQuestions\KnowledgeArticleVersionPublisher::ARRAY_FIELDS;
    $pretty = fn($value) => json_encode($value ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@endphp

<a href="{{ admin_url('knowledge-articles') }}"><i class="fa fa-arrow-left"></i> 返回 Policy 列表</a>
@if($errors->any())<div class="alert alert-danger" style="margin-top:12px"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<div class="row" style="margin-top:12px">
    <div class="col-md-8"><div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">创建下一版本 Draft</h3></div>
        <form method="post" action="{{ admin_url('knowledge-articles/'.$knowledgeArticle->id.'/versions') }}">@csrf<div class="box-body">
            <div class="form-group"><label>标题</label><input class="form-control" name="title" value="{{ old('title', $knowledgeArticle->title) }}"></div>
            <div class="row"><div class="col-md-4 form-group"><label>知识类型</label><select class="form-control" name="knowledge_type">@foreach(\App\Models\KnowledgeArticle::TYPES as $value => $label)<option value="{{ $value }}" @selected(old('knowledge_type', $knowledgeArticle->knowledge_type) === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-md-4 form-group"><label>站点 Key</label><input class="form-control" name="site_key" value="{{ old('site_key', $knowledgeArticle->site_key) }}" placeholder="留空表示 global"></div>
            <div class="col-md-4 form-group"><label>自动化层级</label><select class="form-control" name="automation_tier">@foreach(['draft_low_risk','draft_sensitive','manual_only'] as $value)<option value="{{ $value }}" @selected(old('automation_tier', $knowledgeArticle->automation_tier) === $value)>{{ $value }}</option>@endforeach</select></div></div>
            <div class="form-group"><label>适用范围</label><textarea class="form-control" name="applicability" rows="3">{{ old('applicability', $knowledgeArticle->applicability) }}</textarea></div>
            @foreach($arrayFields as $field)<div class="form-group"><label>{{ $field }}（JSON array）</label><textarea class="form-control" name="{{ $field }}" rows="{{ in_array($field, ['policy_rules','reply_templates','decision_tree']) ? 10 : 5 }}">{{ old($field, $pretty($knowledgeArticle->{$field})) }}</textarea></div>@endforeach
            <div class="form-group"><label>来源与证据说明</label><textarea class="form-control" name="source_notes" rows="4">{{ old('source_notes', $knowledgeArticle->source_notes) }}</textarea></div>
            <div class="form-group"><label>本次修改摘要</label><textarea class="form-control" name="change_summary" rows="3" required>{{ old('change_summary') }}</textarea></div>
        </div><div class="box-footer"><button class="btn btn-primary">保存 Draft 版本</button> <span class="text-muted">不会立即影响生成流程</span></div></form>
    </div></div>
    <div class="col-md-4"><div class="box box-solid"><div class="box-header with-border"><h3 class="box-title">版本历史</h3></div><div class="box-body">
        @foreach($versions as $version)<div style="border-bottom:1px solid #eee;padding:10px 0">
            <strong>v{{ $version->version }}</strong> · {{ $version->status }}<br><span class="text-muted">{{ $version->change_summary ?: '无摘要' }}</span>
            @if($version->status === 'draft')<div style="margin-top:8px;display:flex;gap:6px">
                <form method="post" action="{{ admin_url('knowledge-articles/'.$knowledgeArticle->id.'/versions/'.$version->id.'/publish') }}">@csrf<button class="btn btn-xs btn-success">人工确认并发布</button></form>
                <form method="post" action="{{ admin_url('knowledge-articles/'.$knowledgeArticle->id.'/versions/'.$version->id.'/reject') }}">@csrf<button class="btn btn-xs btn-danger">驳回</button></form>
            </div>@endif
        </div>@endforeach
    </div></div>
    <div class="box box-solid"><div class="box-header with-border"><h3 class="box-title">消费绑定</h3></div><div class="box-body">
        <p class="text-muted">业务知识只保存一份；绑定决定客服或商品问答能否在对应 Intent 下读取。</p>
        @foreach($knowledgeArticle->bindings as $binding)<div style="border-bottom:1px solid #eee;padding:8px 0">
            <strong>{{ \App\Models\KnowledgeArticleBinding::CONSUMERS[$binding->consumer] ?? $binding->consumer }}</strong><br>
            <code>{{ $binding->intent_key }}</code> · {{ \App\Models\KnowledgeArticleBinding::USAGES[$binding->usage] ?? $binding->usage }} · priority {{ $binding->priority }}
            <form method="post" action="{{ admin_url('knowledge-articles/'.$knowledgeArticle->id.'/bindings/'.$binding->id.'/toggle') }}" style="display:inline;margin-left:6px">@csrf<button class="btn btn-xs btn-{{ $binding->enabled ? 'warning' : 'success' }}">{{ $binding->enabled ? '停用' : '启用' }}</button></form>
        </div>@endforeach
        <form method="post" action="{{ admin_url('knowledge-articles/'.$knowledgeArticle->id.'/bindings') }}" style="margin-top:12px">@csrf
            <div class="form-group"><label>消费渠道</label><select class="form-control" name="consumer">@foreach(\App\Models\KnowledgeArticleBinding::CONSUMERS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            <div class="form-group"><label>Intent Key</label><input class="form-control" name="intent_key" value="*" required><p class="help-block">使用 * 表示该渠道的通用知识。</p></div>
            <div class="form-group"><label>用途</label><select class="form-control" name="usage">@foreach(\App\Models\KnowledgeArticleBinding::USAGES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            <div class="form-group"><label>优先级</label><input class="form-control" type="number" min="0" max="1000" name="priority" value="100" required></div>
            <button class="btn btn-default">保存绑定</button>
        </form>
    </div></div></div>
</div>
