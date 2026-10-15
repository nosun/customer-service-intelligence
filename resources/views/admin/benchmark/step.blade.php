@php
    $selectedIntents = old('intents', $annotation?->intents ?? []);
    $selectedFacts = old('required_facts', $annotation?->required_facts ?? []);
    $selectedActions = old('required_actions', $annotation?->required_actions ?? []);
    $selectedKnowledge = array_map('intval', old('knowledge_article_ids', $annotation?->knowledge_article_ids ?? []));
    $selectedEvidence = array_map('intval', old('evidence_event_ids', $annotation?->evidence_event_ids ?? []));
    $missingInformation = old('missing_information', implode("\n", $annotation?->missing_information ?? []));
    $knowledgeGaps = old('knowledge_gaps', implode("\n", $annotation?->knowledge_gaps ?? []));
    $prohibitedClaims = old('prohibited_claims', implode("\n", $annotation?->prohibited_claims ?? []));
    $siteKey = old('site_key', $annotation?->site_key ?? $ticket->site_key ?? 'unknown');
@endphp

<style>
    .step-layout { display: grid; grid-template-columns: minmax(0, 3fr) minmax(380px, 2fr); gap: 18px; align-items: start; }
    .step-event { display: block; border-left: 4px solid #d2d6de; margin-bottom: 12px; padding: 12px 14px; background: #fff; cursor: pointer; }
    .step-event.client, .step-event.customer { border-left-color: #3c8dbc; }
    .step-event.agent { border-left-color: #00a65a; }
    .step-event.private { border-left-color: #f39c12; background: #fff8e6; }
    .step-event.selected { box-shadow: inset 0 0 0 2px #3c8dbc; }
    .step-event-head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
    .step-event-body { overflow-wrap: anywhere; line-height: 1.55; }
    .step-meta { color: #777; font-size: 12px; }
    .step-timeline { background: #f5f5f5; padding: 12px; }
    .step-form .checkbox-list { columns: 2; }
    .step-form .checkbox { margin-top: 4px; margin-bottom: 4px; break-inside: avoid; }
    .knowledge-list { columns: 1 !important; max-height: 280px; overflow-y: auto; border: 1px solid #ddd; padding: 8px 12px; }
    .observed-reply { border-left: 4px solid #00a65a; padding: 12px; margin-bottom: 10px; background: #f7fff7; white-space: pre-wrap; }
    .step-actions { position: sticky; bottom: 0; background: #fff; border-top: 1px solid #ddd; padding-top: 12px; }
    @media (max-width: 1050px) { .step-layout { grid-template-columns: 1fr; } }
    @media (max-width: 620px) { .step-form .checkbox-list { columns: 1; } }
</style>

<p>
    <a href="{{ admin_url('benchmark-cases/'.$case->id) }}"><i class="fa fa-arrow-left"></i> 返回阶段切分</a>
    @if ($nextStep)<a class="btn btn-default btn-xs pull-right" href="{{ admin_url('benchmark-cases/'.$case->id.'/steps/'.$nextStep->id) }}">下一阶段 <i class="fa fa-arrow-right"></i></a>@endif
</p>

<div class="alert alert-warning">
    <strong><i class="fa fa-eye-slash"></i> 阶段盲标：</strong>
    当前只展示截至触发消息 #{{ $step->trigger_event_id }} 的上下文。历史客服后续回复在首次提交个人标注之前保持隐藏。
</div>

@if ($errors->any())
    <div class="alert alert-danger"><strong>保存失败：</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

@if ($step->status === 'adjudicated')
    <div class="box box-success">
        <div class="box-header with-border"><h3 class="box-title">阶段金标准</h3></div>
        <div class="box-body">
            <strong>生命周期：</strong>{{ $lifecycleStages[$step->gold_lifecycle_stage] ?? $step->gold_lifecycle_stage }}　
            <strong>主要意图：</strong>{{ $intents[$step->gold_primary_intent] ?? $step->gold_primary_intent }}　
            <strong>决策：</strong>{{ $decisions[$step->gold_decision] ?? $step->gold_decision }}　
            <strong>自动化：</strong>{{ $automationLevels[$step->gold_automation_level] ?? $step->gold_automation_level }}
        </div>
    </div>
@endif

<div class="step-layout">
    <main>
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">阶段 {{ $step->sequence }}：{{ $step->title }}</h3>
                <span class="pull-right text-muted">{{ $events->count() }} 条可见事件</span>
            </div>
            <div class="box-body">
                <p><strong>阶段截止：</strong>客户消息 #{{ $step->context_end_event_id }}</p>
                @if ($step->stage_end_reason)<p><strong>阶段结束原因：</strong>{{ $step->stage_end_reason }}</p>@endif
                <p class="text-muted">勾选直接支撑当前阶段判断的消息。不要推测截止点之后发生的事情。</p>
            </div>
            <div class="step-timeline">
                @forelse ($events as $event)
                    <label class="step-event {{ $event->author_type }} {{ $event->is_private ? 'private' : '' }} {{ in_array($event->id, $selectedEvidence, true) ? 'selected' : '' }}" for="evidence-{{ $event->id }}">
                        <header class="step-event-head">
                            <span><input id="evidence-{{ $event->id }}" class="step-evidence" type="checkbox" name="evidence_event_ids[]" value="{{ $event->id }}" form="step-annotation-form" @checked(in_array($event->id, $selectedEvidence, true))> <strong>#{{ $event->id }} · {{ $event->author_name ?: ucfirst($event->author_type ?: 'system') }}</strong> <span class="label label-default">{{ $event->author_type ?: $event->event_type }}</span></span>
                            <time class="step-meta">{{ optional($event->occurred_at)->format('Y-m-d H:i:s') ?: '时间未知' }}</time>
                        </header>
                        @if ($event->body_text)<div class="step-event-body">{!! nl2br(e($event->body_text)) !!}</div>@elseif ($event->status_value)<div>状态变更为：{{ $event->status_value }}</div>@else<div class="text-muted">{{ $event->event_type }}</div>@endif
                    </label>
                @empty
                    <p class="text-danger">阶段边界无效，没有可显示的上下文。</p>
                @endforelse
            </div>
        </div>

        @if ($annotation)
            <div class="box box-success">
                <div class="box-header with-border"><h3 class="box-title">提交盲标后的历史回复对照</h3></div>
                <div class="box-body">
                    <p class="text-muted">以下内容只用于评价历史处理和发现知识候选，不能自动成为正确政策。</p>
                    @forelse ($observedReplyEvents as $event)
                        <div class="observed-reply"><strong>#{{ $event->id }} · {{ $event->author_name ?: 'Agent' }}</strong>

{{ $event->body_text ?: '无正文' }}</div>
                    @empty
                        <p class="text-muted">该阶段没有关联历史客服回复。</p>
                    @endforelse
                </div>
            </div>
        @endif
    </main>

    <aside class="box box-solid step-form">
        <div class="box-header with-border"><h3 class="box-title">阶段人工标注</h3></div>
        <form id="step-annotation-form" method="post" action="{{ admin_url('benchmark-cases/'.$case->id.'/steps/'.$step->id.'/annotations') }}">
            @csrf
            <div class="box-body">
                <div class="form-group"><label>站点 *</label><select class="form-control" name="site_key" required>@foreach (['dressafford.com' => 'DAD / dressafford.com', 'ellenbridals.com' => 'EBD / ellenbridals.com', 'unknown' => '无法判断'] as $value => $label)<option value="{{ $value }}" @selected($siteKey === $value)>{{ $label }}</option>@endforeach</select></div>

                <div class="form-group"><label>业务生命周期 *</label><select class="form-control" name="lifecycle_stage" required><option value="">请选择</option>@foreach ($lifecycleStages as $value => $label)<option value="{{ $value }}" @selected(old('lifecycle_stage', $annotation?->lifecycle_stage) === $value)>{{ $label }}</option>@endforeach</select></div>

                <div class="form-group"><label>客户当前目标 *</label><textarea class="form-control" rows="3" name="customer_goal" required placeholder="只描述当前阶段仍需解决的目标">{{ old('customer_goal', $annotation?->customer_goal) }}</textarea></div>

                <div class="form-group"><label>客户意图（可多选）*</label><div class="checkbox-list">@foreach ($intents as $value => $label)<div class="checkbox"><label><input type="checkbox" name="intents[]" value="{{ $value }}" @checked(in_array($value, $selectedIntents, true))> {{ $label }}</label></div>@endforeach</div></div>

                <div class="form-group"><label>主要意图 *</label><select class="form-control" name="primary_intent" required><option value="">请选择</option>@foreach ($intents as $value => $label)<option value="{{ $value }}" @selected(old('primary_intent', $annotation?->primary_intent) === $value)>{{ $label }}</option>@endforeach</select></div>

                <div class="form-group"><label>风险等级 *</label><select class="form-control" name="risk_level" required>@foreach (['normal' => '普通', 'high' => '高风险', 'critical' => '严重风险'] as $value => $label)<option value="{{ $value }}" @selected(old('risk_level', $annotation?->risk_level ?? 'normal') === $value)>{{ $label }}</option>@endforeach</select></div>

                <div class="form-group"><label>正确回复所需事实</label><div class="checkbox-list">@foreach ($requiredFacts as $value => $label)<div class="checkbox"><label><input type="checkbox" name="required_facts[]" value="{{ $value }}" @checked(in_array($value, $selectedFacts, true))> {{ $label }}</label></div>@endforeach</div></div>

                <div class="form-group"><label>当前缺失信息（每行一项）</label><textarea class="form-control" rows="3" name="missing_information">{{ $missingInformation }}</textarea></div>

                <div class="form-group"><label>正确决策 *</label><select class="form-control" name="decision" required><option value="">请选择</option>@foreach ($decisions as $value => $label)<option value="{{ $value }}" @selected(old('decision', $annotation?->decision) === $value)>{{ $label }}</option>@endforeach</select></div>

                <div class="form-group"><label>回复策略 *</label><select class="form-control" name="response_strategy" required><option value="">请选择</option>@foreach ($responseStrategies as $value => $label)<option value="{{ $value }}" @selected(old('response_strategy', $annotation?->response_strategy) === $value)>{{ $label }}</option>@endforeach</select></div>

                <div class="form-group"><label>所需业务动作</label><div class="checkbox-list">@foreach ($requiredActions as $value => $label)<div class="checkbox"><label><input type="checkbox" name="required_actions[]" value="{{ $value }}" @checked(in_array($value, $selectedActions, true))> {{ $label }}</label></div>@endforeach</div></div>

                <hr>
                <h4>依赖知识</h4>
                <div class="checkbox-list knowledge-list">
                    @forelse ($knowledgeArticles as $article)
                        <div class="checkbox"><label><input type="checkbox" name="knowledge_article_ids[]" value="{{ $article->id }}" @checked(in_array($article->id, $selectedKnowledge, true))> <strong>{{ $article->title }}</strong> <span class="label label-{{ $article->status === 'verified' ? 'success' : ($article->status === 'conflicted' ? 'danger' : 'warning') }}">{{ $knowledgeStatuses[$article->status] ?? $article->status }}</span><br><span class="text-muted">{{ $article->key }}</span></label></div>
                    @empty
                        <p class="text-muted">暂无知识卡，可在下方建立候选。</p>
                    @endforelse
                </div>

                <div class="form-group"><label>知识缺口（每行一项）</label><textarea class="form-control" rows="3" name="knowledge_gaps" placeholder="例如：尚未确认页面是否存在备注框">{{ $knowledgeGaps }}</textarea></div>
                <div class="form-group"><label>禁止承诺（每行一项）</label><textarea class="form-control" rows="3" name="prohibited_claims" placeholder="例如：未更新成功前不得声称尺寸已经修改">{{ $prohibitedClaims }}</textarea></div>

                <details class="well well-sm">
                    <summary><strong>新建一条 observed 知识候选</strong></summary>
                    <p class="text-muted" style="margin-top:8px">历史客服做法只能先标记为 observed，经过业务确认后才能升级为 verified。</p>
                    <div class="form-group"><label>知识键</label><input class="form-control" name="new_knowledge_key" value="{{ old('new_knowledge_key') }}" placeholder="例如 post-order-measurement-update"></div>
                    <div class="form-group"><label>标题</label><input class="form-control" name="new_knowledge_title" value="{{ old('new_knowledge_title') }}"></div>
                    <div class="form-group"><label>类型</label><select class="form-control" name="new_knowledge_type">@foreach ($knowledgeTypes as $value => $label)<option value="{{ $value }}" @selected(old('new_knowledge_type', 'process') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="form-group"><label>适用条件</label><textarea class="form-control" rows="2" name="new_knowledge_applicability">{{ old('new_knowledge_applicability') }}</textarea></div>
                    <div class="form-group"><label>来源与待确认事项</label><textarea class="form-control" rows="3" name="new_knowledge_source_notes">{{ old('new_knowledge_source_notes') }}</textarea></div>
                </details>

                <div class="form-group"><label>适用邮件模板</label><select class="form-control" name="email_template_id"><option value="">不使用现有模板</option>@foreach ($templates as $template)<option value="{{ $template->id }}" @selected((string) old('email_template_id', $annotation?->email_template_id) === (string) $template->id)>{{ $template->name }}@if($template->subject) — {{ Illuminate\Support\Str::limit($template->subject, 45) }}@endif</option>@endforeach</select></div>

                <div class="form-group"><label>理想回复</label><textarea class="form-control" rows="8" name="ideal_reply">{{ old('ideal_reply', $annotation?->ideal_reply) }}</textarea></div>

                <div class="form-group"><label>自动化等级 *</label><select class="form-control" name="automation_level" required><option value="">请选择</option>@foreach ($automationLevels as $value => $label)<option value="{{ $value }}" @selected(old('automation_level', $annotation?->automation_level) === $value)>{{ $label }}</option>@endforeach</select><p class="help-block">有未验证知识、缺失信息或业务写操作时，系统禁止选择“可自动发送”。</p></div>

                @if ($annotation)
                    <div class="form-group"><label>历史客服回复评价</label><select class="form-control" name="observed_reply_assessment"><option value="">暂不评价</option>@foreach ($replyAssessments as $value => $label)<option value="{{ $value }}" @selected(old('observed_reply_assessment', $annotation?->observed_reply_assessment) === $value)>{{ $label }}</option>@endforeach</select></div>
                @endif

                <div class="form-group"><label>标注备注</label><textarea class="form-control" rows="3" name="notes">{{ old('notes', $annotation?->notes) }}</textarea></div>
                <div class="checkbox"><label><input type="checkbox" name="mark_as_gold" value="1" @checked(old('mark_as_gold'))> 同时确认为阶段金标准</label></div>

                <div class="step-actions"><button class="btn btn-primary"><i class="fa fa-save"></i> 保存阶段标注</button>@if($annotation)<span class="text-muted" style="margin-left:8px">上次保存：{{ optional($annotation->submitted_at)->format('Y-m-d H:i:s') }}</span>@endif</div>
            </div>
        </form>
    </aside>
</div>

<script>
document.querySelectorAll('.step-evidence').forEach(function (checkbox) {
    checkbox.addEventListener('change', function () {
        checkbox.closest('.step-event').classList.toggle('selected', checkbox.checked);
    });
});
</script>
