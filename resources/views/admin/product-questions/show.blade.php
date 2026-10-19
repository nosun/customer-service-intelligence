@php
    $product = $question->product ?: [];
    $notification = $preview['notification']['status'] ?? null;
    $reviewLabels = ['unreviewed' => '未分析', 'reviewing' => '分析中', 'reviewed' => '已分析', 'ignored' => '已忽略'];
    $analysis = $question->analysis;
    $categories = \App\Models\ProductQuestionAnalysis::CATEGORIES;
    $scopes = \App\Models\ProductQuestionAnalysis::SCOPES;
    $answerability = \App\Models\ProductQuestionAnalysis::ANSWERABILITY;
    $risks = \App\Models\ProductQuestionAnalysis::RISKS;
    $patterns = \App\Models\ProductQuestionAnalysis::REPLY_PATTERNS;
    $responseMoves = \App\Models\ProductQuestionAnalysis::RESPONSE_MOVES;
    $automation = \App\Models\ProductQuestionAnalysis::AUTOMATION;
    $answerSources = \App\Models\ProductQuestionAnalysis::ANSWER_SOURCES;
    $workQueues = \App\Models\ProductQuestionAnalysis::WORK_QUEUES;
    $assessmentLabels = ['good' => '良好', 'partial' => '部分满足', 'poor' => '较差', 'not_applicable' => '不适用'];
    $productAttributes = collect(data_get($productSnapshot?->product_detail, 'attributes', []))
        ->map(function ($value) {
            $values = is_array($value) ? collect($value)->flatten() : collect([$value]);

            return $values
                ->reject(fn ($item) => $item === null || $item === '' || $item === [])
                ->map(fn ($item) => is_bool($item) ? ($item ? '1' : '0') : (string) $item)
                ->implode('、');
        })
        ->filter(fn (string $value) => $value !== '');
    $productImageUrl = $productSnapshot?->primaryImageUrl();
@endphp

<style>
    .qa-detail { display:grid; grid-template-columns:minmax(0,2fr) minmax(300px,1fr); gap:18px; }
    .qa-kv { display:grid; grid-template-columns:105px 1fr; gap:8px 10px; }
    .qa-kv dt { color:#777; font-weight:400; }.qa-kv dd{margin:0;overflow-wrap:anywhere}
    .qa-content { white-space:pre-wrap; overflow-wrap:anywhere; line-height:1.6; }
    .qa-change { display:grid; grid-template-columns:130px 1fr 1fr; gap:8px; padding:8px 0; border-bottom:1px solid #eee; }
    @media(max-width:900px){.qa-detail{grid-template-columns:1fr}.qa-change{grid-template-columns:1fr}}
</style>

<a href="{{ admin_url('product-questions?site_id='.$site->id) }}" style="display:inline-block;margin-bottom:12px"><i class="fa fa-arrow-left"></i> 返回问答列表</a>

<div class="qa-detail">
    <main>
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">问题</h3></div>
            <div class="box-body">
                <div class="qa-content">{{ $question->question ?: '—' }}</div>
                <hr>
                <strong>当前回答</strong>
                <div class="qa-content">{{ $question->answer ? strip_tags($question->answer) : '尚未回复' }}</div>
                @if($question->images)
                    <div style="margin-top:12px">@foreach($question->images as $image)<a href="{{ $image['url'] }}" target="_blank" rel="noopener noreferrer"><img src="{{ $image['url'] }}" alt="QA 图片" style="max-width:150px;max-height:120px;margin-right:8px"></a>@endforeach</div>
                @endif
            </div>
        </div>

        @if($preview)
            <div class="box box-warning">
                <div class="box-header with-border"><h3 class="box-title">确认回复差异</h3></div>
                <div class="box-body">
                    <p>确认令牌有效期至：{{ $preview['confirm_expires_at'] ?? '—' }} @if($notification) · 通知计划：<strong>{{ $notification }}</strong>@endif</p>
                    @forelse(($preview['changes'] ?? []) as $field => $change)
                        <div class="qa-change"><strong>{{ $field }}</strong>
                            @foreach(['before' => '修改前', 'after' => '修改后'] as $side => $label)
                                <div><small>{{ $label }}</small><br>
                                    @if($field === 'answer' && is_string($change[$side] ?? null))
                                        <div class="qa-content">{!! app(\App\Services\ProductQuestions\ProductQuestionReplyFormatter::class)->format($change[$side]) !!}</div>
                                    @else
                                        {{ is_scalar($change[$side] ?? null) || ($change[$side] ?? null) === null ? ($change[$side] ?? 'null') : json_encode($change[$side], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @empty
                        <p class="text-muted">没有字段变化。</p>
                    @endforelse
                </div>
                <div class="box-footer">
                    <form method="post" action="{{ admin_url('product-questions/'.$question->external_id.'/confirm-reply') }}" style="display:inline">
                        @csrf<input type="hidden" name="site_id" value="{{ $site->id }}">
                        <button class="btn btn-warning" type="submit"><i class="fa fa-check"></i> 人工确认并提交</button>
                    </form>
                    <span class="text-muted" style="margin-left:10px">失败重试会复用同一幂等键。</span>
                </div>
            </div>
        @endif

        <div class="box box-{{ $draft?->status === 'generated' ? 'success' : ($draft ? 'warning' : 'default') }}">
            <div class="box-header with-border"><h3 class="box-title">AI Draft（仅草稿）</h3></div>
            <div class="box-body">
                @if($draft)
                    <dl class="qa-kv">
                        <dt>状态</dt><dd>{{ $draft->status }}</dd>
                        <dt>意图</dt><dd>{{ implode('、', $draft->intents ?: []) ?: '—' }}</dd>
                        <dt>建议可见性</dt><dd>{{ $draft->recommended_visible ? '公开' : '隐藏/私密' }}</dd>
                        @if($serviceStrategy = data_get($draft->usage, 'decision_review.plan.service_strategy'))
                            <dt>回复方向</dt><dd>{{ ['answer' => '本轮回答', 'email_handoff' => '邮件继续咨询', 'human_review' => '人工核实'][$serviceStrategy['response_mode'] ?? ''] ?? '—' }}</dd>
                            <dt>判断依据</dt><dd>{{ $serviceStrategy['reason'] ?? '—' }}</dd>
                        @endif
                        <dt>缺失事实</dt><dd>{{ implode('；', $draft->missing_facts ?: []) ?: '无' }}</dd>
                        <dt>安全提示</dt><dd>{{ implode('；', $draft->safety_flags ?: []) ?: '无' }}</dd>
                        <dt>置信度</dt><dd>{{ $draft->confidence === null ? '—' : number_format($draft->confidence * 100, 1).'%' }}</dd>
                    </dl>
                    @if($draft->answer())
                        <hr><strong>草稿内容</strong><div class="qa-content">{!! app(\App\Services\ProductQuestions\ProductQuestionReplyFormatter::class)->format($draft->answer()) !!}</div>
                    @endif
                    @if($draft->error_message)<div class="alert alert-danger" style="margin-top:12px">{{ $draft->error_message }}</div>@endif
                @else
                    <p class="text-muted">尚未生成。生成过程只保存本地草稿，不会 Preview 或发送到站点。</p>
                @endif
            </div>
            <div class="box-footer">
                @if($draftRequest && in_array($draftRequest->status, ['queued', 'running'], true))
                    <div class="callout callout-info">
                        <p><strong>n8n Draft 正在{{ $draftRequest->status === 'queued' ? '排队' : '运行' }}</strong></p>
                        <p class="text-muted">请求 {{ $draftRequest->request_id }}@if($draftRequest->n8n_execution_id) · Execution {{ $draftRequest->n8n_execution_id }}@endif。该流程可能需要数分钟，本页会自动刷新。</p>
                    </div>
                    <script>window.setTimeout(function () { window.location.reload(); }, 5000);</script>
                @elseif($draftRequest && in_array($draftRequest->status, ['failed', 'stale'], true))
                    <div class="callout callout-danger"><p><strong>n8n Draft {{ $draftRequest->status === 'stale' ? '已过期' : '失败' }}</strong></p><p>{{ $draftRequest->error_message }}</p></div>
                @endif
                <form method="post" action="{{ admin_url('product-questions/'.$question->external_id.'/generate-draft') }}">
                    @csrf<input type="hidden" name="site_id" value="{{ $site->id }}">
                    <button class="btn btn-success" type="submit" @if($draftRequest && in_array($draftRequest->status, ['queued', 'running'], true)) disabled @endif><i class="fa fa-magic"></i> {{ $draft ? '检查并复用 Draft' : '通过 n8n 生成 Draft' }}</button>
                    @if($draft)<button class="btn btn-warning" type="submit" name="force" value="1" @if($draftRequest && in_array($draftRequest->status, ['queued', 'running'], true)) disabled @endif><i class="fa fa-refresh"></i> 强制重新生成</button>@endif
                    <span class="text-muted" style="margin-left:8px">生成后仍需人工核对，并继续走 Preview → Confirm。</span>
                </form>
            </div>
        </div>

        <div class="box box-solid">
            <div class="box-header with-border"><h3 class="box-title">{{ $preview ? '重新 Preview' : '回复问答' }}</h3></div>
            <form method="post" action="{{ admin_url('product-questions/'.$question->external_id.'/preview-reply') }}">
                @csrf<input type="hidden" name="site_id" value="{{ $site->id }}">
                <div class="box-body">
                    @if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                    <div class="form-group"><label>回答（HTML）</label><textarea id="qa-answer-source" class="form-control" name="answer" rows="8" required maxlength="100000">{{ old('answer', $draft?->status === 'generated' ? app(\App\Services\ProductQuestions\ProductQuestionReplyFormatter::class)->format($draft->answer() ?? '') : $question->answer) }}</textarea></div>
                    <div class="form-group"><label>实时效果预览</label>
                        <iframe id="qa-answer-preview" title="回答实时效果预览" sandbox="" style="display:block;width:100%;height:260px;border:1px solid #ddd;background:#fff"></iframe>
                        <p class="help-block">上方编辑 HTML，下方实时显示链接、段落和换行；点击“生成 Preview”后再核对修改差异。</p>
                    </div>
                    <div class="form-group"><label>前端展示</label><select class="form-control" name="visible" required><option value="1" @selected((string)old('visible', $draft ? ($draft->recommended_visible ? '1' : '0') : ($question->visible ? '1' : '0')) === '1')>展示</option><option value="0" @selected((string)old('visible', $draft ? ($draft->recommended_visible ? '1' : '0') : ($question->visible ? '1' : '0')) === '0')>隐藏</option></select></div>
                    <div class="form-group"><label>操作原因 <span class="text-muted">（可选）</span></label><input class="form-control" name="reason" value="{{ old('reason') }}" maxlength="1000" placeholder="工单号或业务依据（如有）"></div>
                    <p class="help-block">先 Preview，不会立即修改站点数据。当前阶段不修改图片和 helpful 数值。</p>
                </div>
                <div class="box-footer"><button class="btn btn-primary" type="submit">生成 Preview</button></div>
            </form>
        </div>

        <div class="box box-info">
            <div class="box-header with-border"><h3 class="box-title">本地分析与管理</h3></div>
            <form method="post" action="{{ admin_url('product-questions/'.$question->external_id.'/review') }}">
                @csrf<input type="hidden" name="site_id" value="{{ $site->id }}">
                <div class="box-body">
                    <div class="form-group"><label>分析状态</label><select class="form-control" name="review_status">@foreach($reviewLabels as $value => $label)<option value="{{ $value }}" @selected($question->review_status === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="form-group"><label>标签</label><input class="form-control" name="tags" value="{{ implode(', ', $question->tags ?: []) }}" placeholder="尺码, 交期, 定制"></div>
                    <div class="form-group"><label>分析备注</label><textarea class="form-control" name="analysis_notes" rows="6" maxlength="10000">{{ $question->analysis_notes }}</textarea></div>
                </div>
                <div class="box-footer"><button class="btn btn-info" type="submit">保存分析记录</button></div>
            </form>
        </div>

        <div class="box box-{{ $analysis?->review_status === 'candidate' ? 'warning' : 'success' }}">
            <div class="box-header with-border"><h3 class="box-title">AI 分类与回复模式分析</h3></div>
            @if(!$analysis)
                <div class="box-body"><p class="text-muted">尚未分析。运行 <code>php artisan product-questions:analyze --site={{ $site->site_key }} --question={{ $question->external_id }}</code>。</p></div>
            @else
                <div class="box-body">
                    <dl class="qa-kv">
                        <dt>客户意图</dt><dd>{{ $analysis->customer_intent }}</dd>
                        <dt>意图 Key</dt><dd><code>{{ $analysis->intent_key ?: '旧版分析未生成' }}</code></dd>
                        <dt>分类</dt><dd>{{ $categories[$analysis->primary_category] ?? $analysis->primary_category }}</dd>
                        <dt>范围</dt><dd>{{ $scopes[$analysis->question_scope] ?? $analysis->question_scope }}</dd>
                        <dt>答案来源</dt><dd>{{ $answerSources[$analysis->answer_source] ?? ($analysis->answer_source ?: '旧版分析未生成') }}</dd>
                        <dt>处理队列</dt><dd>{{ $workQueues[$analysis->work_queue] ?? ($analysis->work_queue ?: '旧版分析未生成') }}</dd>
                        <dt>可回答性</dt><dd>{{ $answerability[$analysis->answerability] ?? $analysis->answerability }}</dd>
                        <dt>风险</dt><dd>{{ $risks[$analysis->risk_level] ?? $analysis->risk_level }}</dd>
                        <dt>回复模式</dt><dd>{{ $patterns[$analysis->reply_pattern] ?? $analysis->reply_pattern }}</dd>
                        <dt>自动化建议</dt><dd>{{ $automation[$analysis->automation_recommendation] ?? $analysis->automation_recommendation }}</dd>
                        <dt>置信度</dt><dd>{{ number_format($analysis->confidence * 100, 1) }}%</dd>
                        <dt>分析结论</dt><dd>{{ $analysis->analysis_summary }}</dd>
                        <dt>回复摘要</dt><dd>{{ $analysis->answer_summary ?: '—' }}</dd>
                        <dt>必要事实</dt><dd>{{ implode('；', $analysis->required_facts ?: []) ?: '—' }}</dd>
                        <dt>缺失事实</dt><dd>{{ implode('；', $analysis->missing_facts ?: []) ?: '—' }}</dd>
                        @if($analysis->factory_capability_key)<dt>工厂能力</dt><dd><code>{{ $analysis->factory_capability_key }}</code></dd><dt>待问工厂</dt><dd>{{ $analysis->factory_question }}</dd>@endif
                        <dt>回复动作</dt><dd>{{ implode(' → ', $analysis->response_moves ?: []) ?: '—' }}</dd>
                        <dt>优点</dt><dd>{{ implode('；', $analysis->answer_assessment['strengths'] ?? []) ?: '—' }}</dd>
                        <dt>缺口</dt><dd>{{ implode('；', $analysis->answer_assessment['gaps'] ?? []) ?: '—' }}</dd>
                        <dt>风险表述</dt><dd>{{ implode('；', $analysis->answer_assessment['prohibited_or_risky_claims'] ?? []) ?: '—' }}</dd>
                    </dl>
                    <hr>
                    <form method="post" action="{{ admin_url('product-questions/'.$question->external_id.'/review-analysis') }}">
                        @csrf<input type="hidden" name="site_id" value="{{ $site->id }}">
                        <div class="form-group"><label>客户意图</label><textarea class="form-control" name="customer_intent" rows="2" required maxlength="2000">{{ $analysis->customer_intent }}</textarea></div>
                        <div class="row">
                            <div class="col-md-4 form-group"><label>问题分类</label><select class="form-control" name="primary_category">@foreach($categories as $value => $label)<option value="{{ $value }}" @selected($analysis->primary_category === $value)>{{ $label }}</option>@endforeach</select></div>
                            <div class="col-md-4 form-group"><label>问题范围</label><select class="form-control" name="question_scope">@foreach($scopes as $value => $label)<option value="{{ $value }}" @selected($analysis->question_scope === $value)>{{ $label }}</option>@endforeach</select></div>
                            <div class="col-md-4 form-group"><label>可回答性</label><select class="form-control" name="answerability">@foreach($answerability as $value => $label)<option value="{{ $value }}" @selected($analysis->answerability === $value)>{{ $label }}</option>@endforeach</select></div>
                            <div class="col-md-4 form-group"><label>风险等级</label><select class="form-control" name="risk_level">@foreach($risks as $value => $label)<option value="{{ $value }}" @selected($analysis->risk_level === $value)>{{ $label }}</option>@endforeach</select></div>
                            <div class="col-md-4 form-group"><label>回复模式</label><select class="form-control" name="reply_pattern">@foreach($patterns as $value => $label)<option value="{{ $value }}" @selected($analysis->reply_pattern === $value)>{{ $label }}</option>@endforeach</select></div>
                            <div class="col-md-4 form-group"><label>自动化建议</label><select class="form-control" name="automation_recommendation">@foreach($automation as $value => $label)<option value="{{ $value }}" @selected($analysis->automation_recommendation === $value)>{{ $label }}</option>@endforeach</select></div>
                        </div>
                        <div class="form-group"><label>辅助分类</label><select class="form-control" name="secondary_categories[]" multiple size="4">@foreach($categories as $value => $label)<option value="{{ $value }}" @selected(in_array($value, $analysis->secondary_categories ?: [], true))>{{ $label }}</option>@endforeach</select></div>
                        <div class="row">
                            <div class="col-md-6 form-group"><label>必要事实（每行一项）</label><textarea class="form-control" name="required_facts" rows="5">{{ implode("\n", $analysis->required_facts ?: []) }}</textarea></div>
                            <div class="col-md-6 form-group"><label>缺失事实（每行一项）</label><textarea class="form-control" name="missing_facts" rows="5">{{ implode("\n", $analysis->missing_facts ?: []) }}</textarea></div>
                        </div>
                        <div class="form-group"><label>回复动作</label><select class="form-control" name="response_moves[]" multiple size="5">@foreach($responseMoves as $value => $label)<option value="{{ $value }}" @selected(in_array($value, $analysis->response_moves ?: [], true))>{{ $label }}</option>@endforeach</select><p class="help-block">按住 Command/Ctrl 可多选；保存顺序以页面选项顺序为准。</p></div>
                        <div class="form-group"><label>历史回复摘要</label><textarea class="form-control" name="answer_summary" rows="3">{{ $analysis->answer_summary }}</textarea></div>
                        <div class="form-group"><label>分析结论</label><textarea class="form-control" name="analysis_summary" rows="4" required>{{ $analysis->analysis_summary }}</textarea></div>
                        <h4>历史回复质量</h4>
                        <div class="row">
                            @foreach(['relevance' => '相关性', 'completeness' => '完整性', 'clarity' => '清晰度', 'consistency' => '一致性', 'policy_safety' => '政策安全性'] as $field => $label)
                                <div class="col-md-4 form-group"><label>{{ $label }}</label><select class="form-control" name="assessment_{{ $field }}">@foreach($assessmentLabels as $value => $option)<option value="{{ $value }}" @selected(($analysis->answer_assessment[$field] ?? null) === $value)>{{ $option }}</option>@endforeach</select></div>
                            @endforeach
                        </div>
                        <div class="row">
                            <div class="col-md-4 form-group"><label>优点（每行一项）</label><textarea class="form-control" name="assessment_strengths" rows="4">{{ implode("\n", $analysis->answer_assessment['strengths'] ?? []) }}</textarea></div>
                            <div class="col-md-4 form-group"><label>缺口（每行一项）</label><textarea class="form-control" name="assessment_gaps" rows="4">{{ implode("\n", $analysis->answer_assessment['gaps'] ?? []) }}</textarea></div>
                            <div class="col-md-4 form-group"><label>风险表述（每行一项）</label><textarea class="form-control" name="assessment_risky_claims" rows="4">{{ implode("\n", $analysis->answer_assessment['prohibited_or_risky_claims'] ?? []) }}</textarea></div>
                        </div>
                        <div class="form-group"><label>审核结果</label><select class="form-control" name="review_status"><option value="confirmed" @selected($analysis->review_status === 'confirmed')>确认</option><option value="corrected" @selected($analysis->review_status === 'corrected')>修正后确认</option><option value="rejected" @selected($analysis->review_status === 'rejected')>驳回</option></select></div>
                        <div class="form-group"><label>审核备注</label><textarea class="form-control" name="review_notes" rows="4" maxlength="10000">{{ $analysis->review_notes }}</textarea></div>
                        <button class="btn btn-success" type="submit">保存审核结果</button>
                        <span class="text-muted" style="margin-left:8px">人工审核后，批量分析不会覆盖该结果。</span>
                    </form>
                </div>
            @endif
        </div>
    </main>

    <aside>
        @if($productSnapshot)
            <div class="box box-success"><div class="box-header with-border">
                <h3 class="box-title">产品上下文</h3>
                @if($productSnapshot->product_url)<a class="pull-right" href="{{ $productSnapshot->product_url }}" target="_blank" rel="noopener noreferrer"><i class="fa fa-external-link"></i> 查看商品</a>@endif
            </div><div class="box-body">
                @if($productImageUrl)<img src="{{ $productImageUrl }}" alt="{{ $productSnapshot->name ?: '产品图片' }}" referrerpolicy="no-referrer" loading="lazy" style="display:block;width:100%;max-height:360px;object-fit:contain;margin-bottom:15px">@endif
                <dl class="qa-kv">
                    <dt>产品名</dt><dd>{{ $productSnapshot->name ?: '—' }}</dd>
                    <dt>状态</dt><dd>{{ data_get($productSnapshot->product, 'status_text', $productSnapshot->status) }}</dd>
                    <dt>颜色</dt><dd>{{ implode('、', data_get($productSnapshot->options, 'colors', [])) ?: '—' }}</dd>
                    <dt>尺码</dt><dd>{{ implode('、', data_get($productSnapshot->options, 'sizes', [])) ?: '—' }}</dd>
                    @if($productAttributes->isNotEmpty())<dt>属性</dt><dd>@foreach($productAttributes as $key => $value)<div><strong>{{ $key }}</strong>：{{ $value }}</div>@endforeach</dd>@endif
                    <dt>同步时间</dt><dd>{{ $productSnapshot->synced_at?->format('Y-m-d H:i:s') ?: '—' }}</dd>
                </dl>
            </div></div>
        @else
            <div class="callout callout-warning"><p>尚未同步产品上下文。运行 <code>php artisan product-questions:sync-products --site={{ $site->site_key }}</code>。</p></div>
        @endif
        <div class="box box-solid"><div class="box-header with-border"><h3 class="box-title">问答信息</h3></div><div class="box-body"><dl class="qa-kv">
            <dt>站点</dt><dd>{{ $site->name }}</dd><dt>QA ID</dt><dd>{{ $question->external_id }}</dd><dt>产品 SN</dt><dd>{{ $question->sn ?: '—' }}</dd><dt>产品</dt><dd>{{ $product['name'] ?? '—' }}</dd><dt>用户</dt><dd>{{ $question->name ?: '—' }}</dd><dt>邮箱</dt><dd>{{ $question->email ?: '—' }}</dd><dt>类型</dt><dd>{{ $question->type_text ?: '—' }}</dd><dt>回复状态</dt><dd>{{ $question->reply_status }}</dd><dt>处理状态</dt><dd>{{ $question->status_text ?: $question->status }}</dd><dt>回复来源</dt><dd>{{ $question->reply_source ?: '历史数据未记录' }}@if($question->reply_operator)<div>{{ $question->reply_operator['name'] ?? '—' }} · {{ $question->replied_at?->format('Y-m-d H:i:s') ?: '—' }}</div>@endif</dd>@if($question->close_reason)<dt>关闭原因</dt><dd>{{ $question->close_reason }}</dd>@endif<dt>展示</dt><dd>{{ $question->visible ? '是' : '否' }}</dd><dt>通知</dt><dd>{{ $question->notice_status_text ?: $question->notice_status }}</dd><dt>Helpful</dt><dd>{{ $question->helpful }}</dd><dt>创建时间</dt><dd>{{ optional($question->remote_created_at)->format('Y-m-d H:i:s') ?: '—' }}</dd><dt>同步时间</dt><dd>{{ optional($question->synced_at)->format('Y-m-d H:i:s') ?: '—' }}</dd>
        </dl></div></div>
    </aside>
</div>

<script>
(function () {
    var source = document.getElementById('qa-answer-source');
    var preview = document.getElementById('qa-answer-preview');
    if (!source || !preview) return;
    function render() {
        preview.srcdoc = '<!doctype html><html><head><meta charset="utf-8">'
            + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'">'
            + '<style>body{font:14px/1.6 sans-serif;margin:12px;overflow-wrap:anywhere}a{color:#337ab7}</style>'
            + '</head><body>' + source.value + '</body></html>';
    }
    source.addEventListener('input', render);
    render();
})();
</script>
