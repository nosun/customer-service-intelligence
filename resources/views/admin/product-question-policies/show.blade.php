@php
    $categories = \App\Models\ProductQuestionAnalysis::CATEGORIES;
    $list = fn($items) => implode('；', $items ?: []) ?: '—';
@endphp

<a href="{{ admin_url('product-question-policies') }}"><i class="fa fa-arrow-left"></i> 返回策略列表</a>

<div class="row" style="margin-top:12px">
    <div class="col-md-8">
        <div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">策略内容</h3></div><div class="box-body">
            <dl>
                <dt>场景定义</dt><dd>{{ $candidate->definition }}</dd>
                <dt>适用范围</dt><dd>{{ $candidate->applicability }}</dd>
                <dt>回复前必要事实</dt><dd>{{ $list($candidate->required_facts) }}</dd>
                <dt>候选 Policy</dt><dd><ol>@foreach($candidate->policy_rules as $item)<li>{{ $item }}</li>@endforeach</ol></dd>
                <dt>决策流</dt><dd><ol>@foreach($candidate->decision_tree as $item)<li><strong>如果：</strong>{{ $item['condition'] }}<br><strong>则：</strong>{{ $item['outcome'] }}</li>@endforeach</ol></dd>
                <dt>回复策略</dt><dd><ol>@foreach($candidate->reply_strategy as $item)<li><strong>{{ $item['stage'] }}：</strong>{{ $item['goal'] }}<br>{{ $list($item['content_requirements']) }}</li>@endforeach</ol></dd>
                <dt>Action Flow</dt><dd><ol>@foreach($candidate->action_steps as $item)<li>{{ $item }}</li>@endforeach</ol></dd>
                <dt>禁止承诺</dt><dd>{{ $list($candidate->prohibited_claims) }}</dd>
                <dt>升级人工</dt><dd>{{ $list($candidate->escalation_rules) }}</dd>
                <dt>历史冲突</dt><dd>{{ $list($candidate->conflicts) }}</dd>
                <dt>待确认业务问题</dt><dd>{{ $list($candidate->open_questions) }}</dd>
            </dl>
            <h4>候选回复模板</h4>
            @foreach($candidate->reply_templates as $template)<div class="well"><strong>条件：</strong>{{ $list($template['conditions']) }}<hr><div style="white-space:pre-wrap">{{ $template['body'] }}</div></div>@endforeach
        </div></div>

        <div class="box box-solid"><div class="box-header with-border"><h3 class="box-title">历史证据（{{ $evidence->count() }} 条）</h3></div><div class="box-body">
            @foreach($evidence as $question)<div style="border-bottom:1px solid #eee;padding:10px 0"><strong>QA #{{ $question->external_id }} · {{ $question->sn }}</strong><div>{{ $question->question }}</div><div class="text-muted" style="white-space:pre-wrap">{{ strip_tags((string)$question->answer) }}</div></div>@endforeach
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="box box-warning"><div class="box-header with-border"><h3 class="box-title">人工决策</h3></div><form method="post" action="{{ admin_url('product-question-policies/'.$candidate->id.'/review') }}">
            @csrf<div class="box-body">
                <p>确认后才会发布为 <code>verified</code> 知识，供后续 AI 草稿使用；驳回不会发布。</p>
                <div class="form-group"><label>决策</label><select class="form-control" name="review_status"><option value="confirmed">确认并发布</option><option value="rejected">驳回</option></select></div>
                <div class="form-group"><label>适用范围</label><select class="form-control" name="scope"><option value="site" @selected($candidate->scope === 'site')>仅当前站点</option><option value="global" @selected($candidate->scope === 'global')>所有站点</option></select><p class="help-block">全局策略仍保留当前站点作为历史证据来源。</p></div>
                <div class="form-group"><label>审核备注</label><textarea class="form-control" name="review_notes" rows="6">{{ $candidate->review_notes }}</textarea></div>
            </div><div class="box-footer"><button class="btn btn-warning">保存决策</button></div>
        </form></div>
        <div class="box box-solid"><div class="box-body">
            <p>分类：{{ $categories[$candidate->category] ?? $candidate->category }}</p>
            <p>范围：{{ $candidate->scope === 'global' ? '所有站点' : $candidate->site->name }}</p>
            <p>置信度：{{ number_format($candidate->confidence * 100, 1) }}%</p>
            <p>证据：{{ count($candidate->evidence_question_ids) }} 条</p>
            <p>批次：{{ $candidate->run->batch_key }}</p>
            <p>来源假设：{{ $candidate->run->source_assumption ?: '未设置' }}</p>
            @if($candidate->knowledgeArticle)<p class="text-success">已发布知识：<a href="{{ admin_url('knowledge-articles/'.$candidate->knowledgeArticle->id) }}">{{ $candidate->knowledgeArticle->key }} v{{ $candidate->knowledgeArticle->version }}</a></p>@endif
        </div></div>
    </div>
</div>
