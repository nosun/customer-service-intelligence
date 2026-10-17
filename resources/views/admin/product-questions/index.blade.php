@php
    $replyLabels = ['unanswered' => '待回复', 'answered' => '已回复'];
    $reviewLabels = ['unreviewed' => '未分析', 'reviewing' => '分析中', 'reviewed' => '已分析', 'ignored' => '已忽略'];
    $analysisReviewLabels = ['missing' => '尚未运行', 'candidate' => '待审核', 'confirmed' => '已确认', 'corrected' => '已修正', 'rejected' => '已驳回'];
    $categoryLabels = \App\Models\ProductQuestionAnalysis::CATEGORIES;
    $riskLabels = \App\Models\ProductQuestionAnalysis::RISKS;
    $automationLabels = \App\Models\ProductQuestionAnalysis::AUTOMATION;
    $answerSourceLabels = \App\Models\ProductQuestionAnalysis::ANSWER_SOURCES;
    $workQueueLabels = \App\Models\ProductQuestionAnalysis::WORK_QUEUES;
@endphp

<style>
    .qa-stats { display:grid; grid-template-columns:repeat(6,minmax(120px,1fr)); gap:12px; margin-bottom:16px; }
    .qa-stat { padding:14px; background:#fff; border-radius:3px; box-shadow:0 1px 1px rgba(0,0,0,.1); }
    .qa-stat strong { display:block; font-size:24px; }.qa-stat span,.qa-meta{color:#888;font-size:12px}
    .qa-filters { display:grid; grid-template-columns:repeat(4,minmax(150px,1fr)); gap:10px; align-items:end; }
    .qa-filters label { display:block; color:#777; font-size:12px; }.qa-question{max-width:620px;white-space:normal}
    @media(max-width:900px){.qa-stats,.qa-filters{grid-template-columns:1fr 1fr}}
    @media(max-width:600px){.qa-stats,.qa-filters{grid-template-columns:1fr}}
</style>

@if($sites->isEmpty())
    <div class="callout callout-info"><h4>尚未配置站点</h4><p>请先在<a href="{{ admin_url('commerce-sites/create') }}">站点配置</a>中填写 Admin API 地址和 Token。</p></div>
@else
    <div class="callout callout-{{ $site?->product_question_sync_status === 'failed' ? 'danger' : 'info' }}">
        <strong>{{ $site?->name }}</strong>
        · 同步状态：{{ $site?->product_question_sync_status ?: '尚未同步' }}
        · 最后成功：{{ $site?->product_questions_last_synced_at?->timezone('Asia/Shanghai')?->format('Y-m-d H:i:s') ?: '—' }}
        @if($site?->product_question_sync_error)<div>{{ $site->product_question_sync_error }}</div>@endif
        <div class="qa-meta">命令：php artisan product-questions:sync --site={{ $site?->site_key }}</div>
    </div>

    <div class="qa-stats">
        <div class="qa-stat"><strong>{{ $counts['all'] ?? 0 }}</strong><span>当前问答</span></div>
        <div class="qa-stat"><strong>{{ $counts['unanswered'] ?? 0 }}</strong><span>待回复</span></div>
        <div class="qa-stat"><strong>{{ $counts['unreviewed'] ?? 0 }}</strong><span>未分析</span></div>
        <div class="qa-stat"><strong>{{ $counts['reviewed'] ?? 0 }}</strong><span>已分析</span></div>
        <div class="qa-stat"><strong>{{ $counts['ai_analyzed'] ?? 0 }}</strong><span>AI 已分析</span></div>
        <div class="qa-stat"><strong>{{ $counts['ai_pending'] ?? 0 }}</strong><span>AI 待审核</span></div>
    </div>

    <div class="box box-primary"><div class="box-body">
        <form method="get" action="{{ admin_url('product-questions') }}" class="qa-filters">
            <div><label>站点</label><select class="form-control" name="site_id">@foreach($sites as $option)<option value="{{ $option->id }}" @selected($site?->id === $option->id)>{{ $option->name }}</option>@endforeach</select></div>
            <div><label>关键词</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="问题、回答、商品、SN、姓名或邮箱"></div>
            <div><label>产品 SN</label><input class="form-control" name="sn" value="{{ $filters['sn'] ?? '' }}"></div>
            <div><label>用户邮箱</label><input class="form-control" name="email" value="{{ $filters['email'] ?? '' }}"></div>
            <div><label>回复状态</label><select class="form-control" name="reply_status"><option value="">全部</option>@foreach($replyLabels as $value => $label)<option value="{{ $value }}" @selected(($filters['reply_status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label>回复来源</label><select class="form-control" name="reply_source"><option value="">全部</option><option value="api" @selected(($filters['reply_source'] ?? '') === 'api')>API</option><option value="admin" @selected(($filters['reply_source'] ?? '') === 'admin')>后台人工</option></select></div>
            <div><label>处理状态</label><select class="form-control" name="status"><option value="">全部</option><option value="1" @selected(($filters['status'] ?? '') === '1')>开放</option><option value="0" @selected(($filters['status'] ?? '') === '0')>已关闭</option></select></div>
            <div><label>分析状态</label><select class="form-control" name="review_status"><option value="">全部</option>@foreach($reviewLabels as $value => $label)<option value="{{ $value }}" @selected(($filters['review_status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label>AI 审核状态</label><select class="form-control" name="analysis_review_status"><option value="">全部</option>@foreach($analysisReviewLabels as $value => $label)<option value="{{ $value }}" @selected(($filters['analysis_review_status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label>AI 问题分类</label><select class="form-control" name="primary_category"><option value="">全部</option>@foreach($categoryLabels as $value => $label)<option value="{{ $value }}" @selected(($filters['primary_category'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label>处理队列</label><select class="form-control" name="work_queue"><option value="">全部</option>@foreach($workQueueLabels as $value => $label)<option value="{{ $value }}" @selected(($filters['work_queue'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label>答案来源</label><select class="form-control" name="answer_source"><option value="">全部</option>@foreach($answerSourceLabels as $value => $label)<option value="{{ $value }}" @selected(($filters['answer_source'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label>意图 Key</label><input class="form-control" name="intent_key" value="{{ $filters['intent_key'] ?? '' }}" placeholder="request_add_pockets"></div>
            <div><label>风险等级</label><select class="form-control" name="risk_level"><option value="">全部</option>@foreach($riskLabels as $value => $label)<option value="{{ $value }}" @selected(($filters['risk_level'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label>自动化建议</label><select class="form-control" name="automation_recommendation"><option value="">全部</option>@foreach($automationLabels as $value => $label)<option value="{{ $value }}" @selected(($filters['automation_recommendation'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label>问题类型</label><select class="form-control" name="type"><option value="">全部</option><option value="1" @selected(($filters['type'] ?? '') === '1')>用户提问</option><option value="0" @selected(($filters['type'] ?? '') === '0')>原创 QA</option></select></div>
            <div><label>展示状态</label><select class="form-control" name="visible"><option value="">全部</option><option value="1" @selected(($filters['visible'] ?? '') === '1')>展示</option><option value="0" @selected(($filters['visible'] ?? '') === '0')>隐藏</option></select></div>
            <div><button class="btn btn-primary"><i class="fa fa-search"></i> 查询</button> <a class="btn btn-default" href="{{ admin_url('product-questions?site_id='.$site?->id) }}">清空</a></div>
        </form>
    </div></div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">{{ $site?->name }} 问答</h3><span class="pull-right text-muted">{{ $questions->total() }} 条</span></div>
        <div class="box-body table-responsive no-padding"><table class="table table-hover">
            <thead><tr><th>ID / 产品</th><th>问题</th><th>用户</th><th>业务状态</th><th>人工记录</th><th>AI 分析</th><th>更新时间</th></tr></thead>
            <tbody>
            @forelse($questions as $question)
                <tr>
                    <td><a href="{{ admin_url('product-questions/'.$question->external_id.'?site_id='.$site->id) }}">#{{ $question->external_id }}</a><div class="qa-meta">SN {{ $question->sn ?: '—' }}</div></td>
                    <td class="qa-question"><a href="{{ admin_url('product-questions/'.$question->external_id.'?site_id='.$site->id) }}">{{ $question->question ?: '—' }}</a>@if($question->answer)<div class="qa-meta">{{ strip_tags($question->answer) }}</div>@endif @if($question->tags)<div>@foreach($question->tags as $tag)<span class="label label-info">{{ $tag }}</span> @endforeach</div>@endif</td>
                    <td>{{ $question->name ?: '—' }}<div class="qa-meta">{{ $question->email }}</div></td>
                    <td><span class="label label-{{ $question->reply_status === 'unanswered' ? 'warning' : 'success' }}">{{ $replyLabels[$question->reply_status] ?? $question->reply_status }}</span><div class="qa-meta">{{ $question->visible ? '展示' : '隐藏' }} · helpful {{ $question->helpful }}</div></td>
                    <td><span class="label label-default">{{ $reviewLabels[$question->review_status] ?? $question->review_status }}</span></td>
                    <td>@if($question->analysis)<span class="label label-info">{{ $categoryLabels[$question->analysis->primary_category] ?? $question->analysis->primary_category }}</span>@if($question->analysis->work_queue)<span class="label label-warning">{{ $workQueueLabels[$question->analysis->work_queue] ?? $question->analysis->work_queue }}</span>@endif<div class="qa-meta">{{ $question->analysis->intent_key ?: '旧版分析' }} · {{ $riskLabels[$question->analysis->risk_level] ?? $question->analysis->risk_level }} · {{ $analysisReviewLabels[$question->analysis->review_status] ?? $question->analysis->review_status }}</div>@else<span class="text-muted">尚未运行</span>@endif</td>
                    <td>{{ optional($question->remote_updated_at)->format('Y-m-d H:i:s') ?: '—' }}<div class="qa-meta">同步 {{ optional($question->synced_at)->diffForHumans() }}</div></td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted" style="padding:40px">尚无本地问答，请先运行同步命令。</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @if($questions->hasPages())<div class="box-footer clearfix">{{ $questions->links() }}</div>@endif
    </div>
@endif
