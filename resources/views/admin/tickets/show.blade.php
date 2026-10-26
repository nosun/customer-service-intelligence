@php
    $case = $ticket->decisionCase;
    $draft = $ticket->drafts->first();
    $ticketWorkItems = $ticket->runtimeCaseSource?->customerCase?->workItems ?? collect();
    $latestTicketWorkItem = $ticketWorkItems->first();
    $decisionConflicts = array_merge((array) data_get($latestTicketWorkItem?->result, 'review.guidance_conflicts', []), (array) data_get($latestTicketWorkItem?->result, 'review.business_conflicts', []));
    $responseDecision = data_get($latestTicketWorkItem?->result, 'reply_plan.response_decision');
    $caseProgress = data_get($latestTicketWorkItem?->result, 'reply_plan.case_progress');
    $reviewLabels = ['candidate' => '待审核', 'confirmed' => '已确认', 'corrected' => '已修正', 'rejected' => '已拒绝'];
    $assignment = $ticket->assignment_snapshot ?: [];
    $cancellationContext = $ticket->latestCancellationContext;
    $businessSummary = $cancellationContext?->summary ?: [];
    $outcomeLabels = [
        'cancelled_refund_pending' => '已取消，退款记录未全部完成',
        'cancelled_refund_recorded' => '已取消，已记录退款',
        'cancelled_without_refund_record' => '已取消，未查到退款记录',
        'not_cancelled_refund_recorded' => '订单未取消，但已有退款记录',
        'not_cancelled' => '订单未取消',
        'order_not_found' => '订单不存在',
    ];
@endphp

<style>
    .ticket-detail { display: grid; grid-template-columns: minmax(0, 2fr) minmax(330px, 1fr); gap: 18px; }
    .ticket-back { margin-bottom: 12px; display: inline-block; }
    .timeline-event { border-left: 4px solid #d2d6de; margin-bottom: 14px; padding: 12px 16px; background: #fff; box-shadow: 0 1px 1px rgba(0,0,0,.08); }
    .timeline-event.customer, .timeline-event.client { border-left-color: #3c8dbc; }
    .timeline-event.agent { border-left-color: #00a65a; }
    .timeline-event.private { background: #fff8e6; border-left-color: #f39c12; }
    .event-head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
    .event-body { white-space: normal; overflow-wrap: anywhere; line-height: 1.55; }
    .event-meta { color: #888; font-size: 12px; }
    .detail-kv { display: grid; grid-template-columns: 105px 1fr; gap: 7px 10px; }
    .detail-kv dt { color: #777; font-weight: 400; }
    .detail-kv dd { margin: 0; overflow-wrap: anywhere; }
    .node-card { border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin-bottom: 12px; }
    .node-card summary { cursor: pointer; font-weight: 600; }
    .node-form { margin-top: 12px; }
    .node-form label, .case-form label { color: #666; font-size: 12px; }
    .evidence-list { margin: 8px 0; padding-left: 20px; }
    .attachment-list { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 0; padding: 0; list-style: none; }
    .attachment-item { max-width: 220px; }
    .attachment-preview { display: block; max-width: 220px; max-height: 160px; margin-bottom: 4px; border: 1px solid #ddd; border-radius: 3px; }
    .attachment-meta { color: #888; font-size: 12px; }
    @media (max-width: 980px) { .ticket-detail { grid-template-columns: 1fr; } }
</style>

@if($latestTicketWorkItem && in_array($latestTicketWorkItem->status, ['queued', 'running'], true))
    <meta http-equiv="refresh" content="5">
@endif

<a class="ticket-back" href="{{ admin_url('conversations') }}"><i class="fa fa-arrow-left"></i> 返回 Ticket 队列</a>

<div class="ticket-detail">
    <main>
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">消息时间线</h3>
                <span class="pull-right text-muted">{{ $ticket->events->count() }} 条事件</span>
            </div>
            <div class="box-body" style="background:#f5f5f5">
                @forelse ($ticket->events as $event)
                    <article id="event-{{ $event->id }}" class="timeline-event {{ $event->author_type }} {{ $event->is_private ? 'private' : '' }}">
                        <header class="event-head">
                            <div><strong>{{ $event->author_name ?: ucfirst($event->author_type ?: 'system') }}</strong> <span class="label label-default">{{ $event->author_type ?: $event->event_type }}</span> @if($event->is_private)<span class="label label-warning">私密</span>@endif</div>
                            <time class="event-meta">{{ optional($event->occurred_at)->format('Y-m-d H:i:s') ?: '时间未知' }}</time>
                        </header>
                        @if ($event->author_email)<div class="event-meta">{{ $event->author_email }}</div>@endif
                        @if ($event->body_text)<div class="event-body">{!! nl2br(e($event->body_text)) !!}</div>@elseif ($event->status_value)<div class="event-body">状态变更为：{{ $event->status_value }}</div>@else<div class="event-body text-muted">{{ $event->event_type }}</div>@endif
                        @if ($event->attachments->isNotEmpty())
                            <ul class="attachment-list">
                                @foreach ($event->attachments as $attachment)
                                    @php($isImage = Illuminate\Support\Str::startsWith((string) $attachment->mime_type, 'image/') && $attachment->mime_type !== 'image/heic')
                                    <li class="attachment-item">
                                        @if ($attachment->remote_url)
                                            <a href="{{ $attachment->remote_url }}" target="_blank" rel="noopener noreferrer">
                                                @if ($isImage)<img class="attachment-preview" src="{{ $attachment->remote_url }}" alt="{{ $attachment->filename ?: '附件' }}" loading="lazy">@endif
                                                <i class="fa fa-paperclip"></i> {{ $attachment->filename ?: '附件' }}
                                            </a>
                                        @else
                                            <i class="fa fa-paperclip"></i> {{ $attachment->filename ?: '附件' }}
                                        @endif
                                        <div class="attachment-meta">
                                            @if($attachment->mime_type){{ $attachment->mime_type }}@endif
                                            @if($attachment->size_bytes) · {{ Illuminate\Support\Number::fileSize($attachment->size_bytes) }}@endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </article>
                @empty
                    <p class="text-muted text-center">此 Ticket 尚无消息事件。</p>
                @endforelse
            </div>
        </div>

        @include('admin.tickets.assistance')

        @include('admin.tickets.order-workbench')

        <div class="box box-success" id="reply-draft">
            <div class="box-header with-border"><h3 class="box-title">3. 邮件草稿与回复指南</h3></div>
            <div class="box-body" style="border-bottom:1px solid #eee">
                <form method="post" action="{{ admin_url('conversations/'.$ticket->id.'/generate-draft') }}">
                    @csrf
                    <input type="hidden" name="inspect_order" value="1">
                    <div class="form-group">
                        <label>当前 Ticket 回复指南</label>
                        <p class="text-muted">填写已调查事实、工厂答复或本次回复要求。仅用于当前 Ticket，生成时一并保存，并自动补充或刷新订单核查。</p>
                        <textarea class="form-control" rows="4" name="draft_guidance" maxlength="10000">{{ old('draft_guidance', $ticket->draft_guidance) }}</textarea>
                        <p class="text-muted">例如客户说 return，但订单尚未发货时，请说明是在询问整单取消，还是需要先向客户澄清。核准处理不等于已经完成取消或退款。</p>
                    </div>
                    <button class="btn btn-primary" @disabled($latestTicketWorkItem && in_array($latestTicketWorkItem->status, ['queued', 'running'], true))>
                        <i class="fa fa-magic"></i> 核查并生成回复草稿
                    </button>
                    <button class="btn btn-default" formaction="{{ admin_url('conversations/'.$ticket->id.'/agent-assist') }}" @disabled($latestTicketWorkItem && in_array($latestTicketWorkItem->status, ['queued', 'running'], true))>
                        Agents SDK 调查并拟稿
                    </button>
                    <span class="text-muted" style="margin-left:10px">保存指南 → 核查订单 → 生成本地草稿；发送仍在 HelpDesk。</span>
                </form>
                @if($latestTicketWorkItem)
                    @if(in_array($latestTicketWorkItem->status, ['queued', 'running'], true))
                        <p class="text-info" style="margin-top:10px">草稿正在后台生成。<a href="{{ admin_url('conversations/'.$ticket->id) }}#reply-draft">刷新查看结果</a></p>
                    @endif
                    <hr>
                    <dl class="detail-kv">
                        <dt>生成状态</dt><dd>{{ ['queued' => '等待生成', 'running' => '正在生成', 'completed' => '生成结束', 'failed' => '生成失败', 'stale' => '输入已变化，请重新生成', 'cancelled' => '已停止'][$latestTicketWorkItem->status] ?? $latestTicketWorkItem->status }}@if(data_get($latestTicketWorkItem->result, 'draft_status') === 'blocked') · 需补充或核实依据，尚不可采用@elseif(data_get($latestTicketWorkItem->result, 'draft_status') === 'draft') · 待人工审核@endif</dd>
                    </dl>
                    @if($responseDecision)
                        <div class="alert alert-info">
                            @if(!($responseCurrent ?? true))<p class="text-warning">消息、关联记录或依据已变化，以下为历史判断，请重新评估；不应继续依据旧建议等待或回复。</p>@endif
                            <strong>回应建议：{{ ['reply' => '需要实质回复', 'acknowledge' => '建议简短确认', 'no_reply' => '建议暂不回复'][$responseDecision['mode']] ?? '待判断' }}</strong>
                            <p>{{ $responseDecision['reason'] }}</p>
                            @if($caseProgress)
                                <p><strong>当前进度：</strong>{{ $caseProgress['summary'] }}</p>
                                <p><strong>下一步：</strong>{{ $caseProgress['next_action'] }}（{{ ['none' => '无需等待', 'customer' => '等待客户', 'internal' => '内部跟进'][$caseProgress['waiting_for']] ?? '待确认' }}）</p>
                                @if(data_get($caseProgress, 'follow_up_note'))<p>跟进建议：{{ $caseProgress['follow_up_note'] }}（尚未创建提醒）</p>@endif
                            @endif
                            @if(data_get($latestTicketWorkItem->result, 'draft_status') === 'no_reply')<p>独立复核已通过，未创建邮件草稿；请人工确认，Ticket 状态未自动改变。</p>@endif
                            @if(data_get($latestTicketWorkItem->result, 'draft_status') === 'needs_review')<p class="text-warning">不回复建议未通过独立复核，请人工判断，不能当作已完成处理。</p>@endif
                        </div>
                    @endif
                    @if(data_get($latestTicketWorkItem->result, 'runtime_audit.ticket_context.history_incomplete') || data_get($latestTicketWorkItem->result, 'reply_plan.history_incomplete'))
                        <div class="alert alert-warning">关联记录过多，上下文不完整，请人工核对历史后处理。</div>
                    @endif
                    @if($latestTicketWorkItem->error_message)<div class="alert alert-danger" style="margin-bottom:0">{{ $latestTicketWorkItem->error_message }}</div>@endif
                    @if(data_get($latestTicketWorkItem->result, 'return_assessment.applicable'))
                        @php($returnAssessment = data_get($latestTicketWorkItem->result, 'return_assessment'))
                        <div class="alert alert-info">
                            <strong>退货处理阶段：</strong>{{ ['collect_conditions' => '第一阶段：补齐必要条件', 'offer_options' => '第二阶段：提供退货方案', 'send_instructions' => '第三阶段：正式寄回指引', 'track_return' => '退件运输与签收跟进', 'review_refund' => '收件、质检与退款核查', 'exception_review' => '具体争议或例外核查'][$returnAssessment['stage']] ?? $returnAssessment['stage'] }}
                            <span>（{{ $returnAssessment['template_id'] }}）</span>
                            <p>{{ $returnAssessment['reason'] }}</p>
                            <p><strong>回复准备情况：</strong>{{ ['ready' => '已形成当前阶段回复，待人工审核', 'internal_review' => '有内部待核实项，不能直接采纳', 'holding' => '仅过渡沟通，尚未形成正式方案'][data_get($latestTicketWorkItem->result, 'reply_readiness')] ?? '本次未生成新邮件' }}</p>
                            <details><summary>查看逐项条件判断与依据</summary><ul>
                                @foreach($returnAssessment['conditions'] as $condition)<li>{{ $condition['key'] }}：{{ $condition['status'] }} — {{ $condition['explanation'] }}</li>@endforeach
                            </ul></details>
                            @if(data_get($returnAssessment, 'routing_rule'))
                                <details><summary>查看流程版本与当前步骤的完成标准</summary>
                                    <p>流程版本：{{ $returnAssessment['strategy_version'] }}；阶段规则：{{ $returnAssessment['routing_rule'] }}</p>
                                    @if(data_get($returnAssessment, 'requires_internal_review'))<p class="text-warning">当前进度已保留，但新发现的冲突需要内部核实后才能采用回复。</p>@endif
                                    <ul>
                                        <li>分析完成：{{ data_get($returnAssessment, 'node_contract.completion.analysis') }}</li>
                                        <li>草稿完成：{{ data_get($returnAssessment, 'node_contract.completion.draft') }}</li>
                                        <li>业务完成：{{ data_get($returnAssessment, 'node_contract.completion.business') }}</li>
                                    </ul>
                                </details>
                            @endif
                        </div>
                    @endif
                    @if(data_get($latestTicketWorkItem->result, 'draft_status') === 'blocked')
                        <p class="text-warning">请查看下方阻止原因，补充或纠正回复指南后重新生成。原草稿仍保留供查看。</p>
                    @endif
                    @if($decisionConflicts)
                        <div class="alert alert-warning"><strong>需要核实的处理冲突：</strong><ul>
                            @foreach($decisionConflicts as $conflict)<li>{{ is_string($conflict) ? $conflict : (data_get($conflict, 'message') ?? data_get($conflict, 'description') ?? json_encode($conflict, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}</li>@endforeach
                        </ul></div>
                    @endif
                    @if(data_get($latestTicketWorkItem->result, 'reply_plan.answer_units'))
                        <p><strong>本次处理方向：</strong></p>
                        <ul>
                            @foreach(data_get($latestTicketWorkItem->result, 'reply_plan.answer_units', []) as $unit)
                                <li>
                                    {{ ['presales' => '售前', 'in_sales' => '售中', 'after_sales' => '售后', 'undetermined' => '阶段待确认'][data_get($unit, 'business_stage')] ?? '阶段待确认' }}
                                    → {{ ['presales_consultation' => '咨询', 'production_cancellation' => '取消订单', 'standard_size_change' => '标准改码', 'production_order_status' => '订单进度', 'logistics_tracking_delivery' => '物流跟进', 'refund_review' => '退款核查', 'donation_refund' => '捐赠退款', 'after_sales_complaint' => '投诉/退货', 'factory_clarification' => '工厂确认', 'conversation_update' => '沟通进度更新'][data_get($unit, 'business')] ?? data_get($unit, 'business') }}
                                    → {{ ['information' => '说明已知情况', 'clarification' => '需要澄清', 'requested' => '已收到请求', 'decided' => '已决定将办理', 'arranged' => '已安排', 'completed' => '已完成并核实'][data_get($unit, 'action_stage')] ?? data_get($unit, 'action_stage') }}
                                    @if(data_get($unit, 'template'))<span class="text-muted">（模板 {{ data_get($unit, 'template.id') }} · {{ data_get($unit, 'template.article_key') }} v{{ data_get($unit, 'template.article_version') }}）</span>@else<span class="text-muted">（尚无匹配模板，按本案依据拟稿）</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if(data_get($latestTicketWorkItem->result, 'reply_plan.internal_followups'))
                        <div class="alert alert-info"><strong>内部待核查/跟进（尚未执行）：</strong><ul>@foreach(data_get($latestTicketWorkItem->result, 'reply_plan.internal_followups', []) as $followup)<li>{{ $followup }}</li>@endforeach</ul></div>
                    @endif
                    @if(data_get($latestTicketWorkItem->result, 'context_sources.related_conversations'))<p>关联会话：@foreach(data_get($latestTicketWorkItem->result, 'context_sources.related_conversations', []) as $source)<a href="{{ admin_url('conversations/'.$source['conversation_id']) }}">#{{ $source['conversation_id'] }}</a> @endforeach<span class="text-muted">（历史资料，不作为当前操作授权）</span></p>@endif
                    <details><summary>生成依据与运行详情</summary>
                    <p>任务：{{ $latestTicketWorkItem->work_item_key }} · {{ $latestTicketWorkItem->workflow_key }}@{{ $latestTicketWorkItem->workflow_version }}</p>
                    @if(data_get($latestTicketWorkItem->result, 'safety_flags'))
                        <div class="alert alert-warning" style="margin-bottom:0"><strong>安全检查：</strong><ul>@foreach(data_get($latestTicketWorkItem->result, 'safety_flags', []) as $flag)<li>{{ $flag }}</li>@endforeach</ul></div>
                    @endif
                    @if(data_get($latestTicketWorkItem->result, 'plan.intents'))
                        <p><strong>识别意图：</strong> {{ implode('、', data_get($latestTicketWorkItem->result, 'plan.intents', [])) }}</p>
                    @endif
                    @if(data_get($latestTicketWorkItem->result, 'facts'))
                        <p><strong>只读事实：</strong></p>
                        <ul>
                            @foreach(data_get($latestTicketWorkItem->result, 'facts', []) as $fact)
                                <li><code>{{ data_get($fact, 'key') }}</code> = {{ is_scalar(data_get($fact, 'value')) ? var_export(data_get($fact, 'value'), true) : json_encode(data_get($fact, 'value'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }} <span class="text-muted">（{{ data_get($fact, 'source') }}，{{ data_get($fact, 'captured_at') }}）</span></li>
                            @endforeach
                        </ul>
                    @endif
                    @if(data_get($latestTicketWorkItem->result, 'missing_facts'))
                        <p><strong>缺失事实：</strong> {{ implode('、', data_get($latestTicketWorkItem->result, 'missing_facts', [])) }}</p>
                    @endif
                    @if(data_get($latestTicketWorkItem->result, 'knowledge_articles'))
                        <p><strong>使用 Policy：</strong></p>
                        <ul>@foreach(data_get($latestTicketWorkItem->result, 'knowledge_articles', []) as $policy)<li>#{{ data_get($policy, 'id') }} {{ data_get($policy, 'key') }} v{{ data_get($policy, 'version') }}</li>@endforeach</ul>
                    @endif
                    </details>
                @endif
            </div>
            <form method="post" action="{{ admin_url('conversations/'.$ticket->id.'/draft') }}">
                @csrf
                <div class="box-body">
                    <div class="alert alert-info"><i class="fa fa-info-circle"></i> 当前只保存到本地，不会发送至 HelpDesk。@if($draft) 当前内容来源：{{ $draft->source }}，状态：{{ $draft->status }}。@endif</div>
                    @if($draft && !($draftCurrent ?? true))<div class="alert alert-warning">消息、指南、订单事实或政策已变化，或草稿尚未记录核查依据；请更新并复核草稿。</div>@endif
                    @if($draft && $latestTicketWorkItem && $draft->work_item_id !== $latestTicketWorkItem->id && in_array(data_get($latestTicketWorkItem->result, 'draft_status'), ['no_reply', 'needs_review'], true))<div class="alert alert-warning">下方为历史草稿，本次回应判断没有生成新邮件，请勿将历史内容当作本次回复。</div>@endif
                    <div class="form-group"><label>草稿内容</label><textarea id="ticket-reply-body" class="form-control" rows="8" name="body" placeholder="输入回复或内部备注草稿">{{ old('body', $draft?->body) }}</textarea></div>
                    <div class="row">
                        <div class="col-sm-6"><div class="checkbox"><label><input type="checkbox" name="is_private" value="1" @checked(old('is_private', $draft?->is_private))> 私密备注</label></div></div>
                        <div class="col-sm-6"><select class="form-control" name="status_after_send"><option value="">发送后状态（暂不执行）</option>@foreach (['open', 'pending', 'onhold', 'solved', 'closed'] as $status)<option value="{{ $status }}" @selected(old('status_after_send', $draft?->status_after_send) === $status)>{{ $status }}</option>@endforeach</select></div>
                    </div>
                </div>
                <div class="box-footer"><button class="btn btn-success"><i class="fa fa-save"></i> 保存本地草稿</button> <button type="button" class="btn btn-default" onclick="navigator.clipboard.writeText(document.getElementById('ticket-reply-body').value).then(() => { this.textContent = '已复制'; }).catch(() => { this.textContent = '请选中草稿手动复制'; })">复制草稿</button> <a class="btn btn-default" href="https://app.helpdesk.com/tickets/{{ rawurlencode($ticket->external_id) }}" target="_blank" rel="noopener noreferrer">在 HelpDesk 处理</a>@if($draft)<span class="text-muted" style="margin-left:10px">上次保存：{{ $draft->updated_at->format('Y-m-d H:i:s') }}</span>@endif</div>
            </form>
            @if(in_array($draft?->source, ['n8n', 'openai_agents'], true))
                @if(data_get($draft->metadata, 'answer_zh'))
                    <details class="box-body"><summary>中文对应邮件</summary><pre style="white-space:pre-wrap">{{ data_get($draft->metadata, 'answer_zh') }}</pre></details>
                @endif
                <form method="post" action="{{ admin_url('conversations/'.$ticket->id.'/drafts/'.$draft->id.'/review') }}" style="border-top:1px solid #eee">
                    @csrf
                    <div class="box-body">
                        <div class="form-group"><label>审核后的回复内容</label><textarea class="form-control" rows="5" name="body" @disabled($draft->status === 'blocked')>{{ $draft->body }}</textarea></div>
                        <div class="form-group">
                            <label>AI Draft 人工审核</label>
                            <select class="form-control" name="review_outcome" required>
                                <option value="accepted_as_is" @selected($draft->review_outcome === 'accepted_as_is') @disabled($draft->status === 'blocked')>原样采用</option>
                                <option value="edited" @selected($draft->review_outcome === 'edited') @disabled($draft->status === 'blocked')>编辑后采用</option>
                                <option value="rejected" @selected($draft->review_outcome === 'rejected')>拒绝</option>
                            </select>
                        </div>
                        <div class="form-group"><label>审核备注</label><textarea class="form-control" rows="2" name="review_notes">{{ $draft->review_notes }}</textarea></div>
                        <button class="btn btn-warning"><i class="fa fa-check"></i> 保存审核结果</button>
                        <span class="text-muted" style="margin-left:10px">审核不等于发送。</span>
                    </div>
                </form>
            @endif
        </div>
    </main>

    <aside>
        <div class="box box-solid">
            <div class="box-header with-border"><h3 class="box-title">Ticket 信息</h3></div>
            <div class="box-body"><dl class="detail-kv">
                <dt>Ticket ID</dt><dd>{{ $ticket->external_short_id ?: $ticket->external_id }}</dd>
                @if($ticket->external_short_id && $ticket->external_id !== $ticket->external_short_id)<dt>外部 ID</dt><dd>{{ $ticket->external_id }}</dd>@endif
                <dt>请求人</dt><dd>{{ $ticket->requester_email ?: '未知' }}</dd>
                <dt>站点</dt><dd>{{ $ticket->site_key ?: '未识别' }}</dd>
                <dt>状态</dt><dd>{{ $ticket->status ?: 'unknown' }} / {{ $ticket->silo ?: 'unknown' }}</dd>
                <dt>优先级</dt><dd>{{ $ticket->priority ?? '—' }}</dd>
                <dt>渠道</dt><dd>{{ $ticket->channelAccount?->display_name ?: '—' }}</dd>
                <dt>分配</dt><dd>{{ data_get($assignment, 'team.name') ?: data_get($assignment, 'agent.name') ?: data_get($assignment, 'name') ?: '—' }}</dd>
                <dt>最后消息</dt><dd>{{ optional($ticket->last_message_at)->format('Y-m-d H:i:s') ?: '—' }}</dd>
                <dt>本地同步</dt><dd>{{ optional($ticket->updated_at)->format('Y-m-d H:i:s') ?: '—' }}</dd>
            </dl></div>
        </div>

        <div class="box box-solid">
            <div class="box-header with-border"><h3 class="box-title">分类与标签</h3></div>
            <div class="box-body"><dl class="detail-kv">
                <dt>主要类别</dt><dd>{{ $ticket->analysis?->primary_category ?: '未分析' }}</dd>
                <dt>问题类型</dt><dd>{{ $ticket->analysis?->primary_problem_type ?: '—' }}</dd>
                <dt>紧急度</dt><dd>{{ $ticket->analysis?->urgency ?: '—' }}</dd>
                <dt>需复核</dt><dd>{{ $ticket->analysis?->needs_review ? '是' : '否' }}</dd>
            </dl>
            @if($ticket->labels->isNotEmpty())<hr>@foreach($ticket->labels as $label)<span class="label label-info" style="display:inline-block;margin:2px">{{ $label->label_type }}: {{ $label->label }}</span>@endforeach @endif
            </div>
        </div>

        @if($cancellationContext)
        <div class="box box-info">
            <div class="box-header with-border"><h3 class="box-title">历史取消核验记录</h3></div>
            <div class="box-body"><details><summary>展开旧核验记录（最新事实请使用订单核查）</summary>
                @if($cancellationContext)
                    <hr>
                    <dl class="detail-kv">
                        <dt>查询状态</dt><dd>{{ $cancellationContext->lookup_status }}</dd>
                        <dt>订单号</dt><dd>{{ $cancellationContext->lookup_key ?: '未识别' }}</dd>
                        <dt>业务结果</dt><dd>{{ $outcomeLabels[$cancellationContext->outcome] ?? ($cancellationContext->outcome ?: '未知') }}</dd>
                        <dt>订单状态</dt><dd>{{ data_get($businessSummary, 'status.order_status_text', '—') }}</dd>
                        <dt>发货状态</dt><dd>{{ data_get($businessSummary, 'status.shipping_status_text', '—') }}</dd>
                        <dt>取消时间</dt><dd>{{ data_get($businessSummary, 'time.customer_canceled_at', '—') }}</dd>
                        <dt>退款金额</dt><dd>{{ data_get($businessSummary, 'amounts.refund', '—') }}</dd>
                        <dt>退款记录</dt><dd>{{ data_get($businessSummary, 'assessment.completed_refund_records', 0) }} 已完成 / {{ data_get($businessSummary, 'assessment.pending_refund_records', 0) }} 未完成</dd>
                        <dt>邮箱匹配</dt><dd>{{ data_get($businessSummary, 'identity_check.email_match') === null ? '未核实' : (data_get($businessSummary, 'identity_check.email_match') ? '匹配' : '不匹配') }}</dd>
                        <dt>政策判断</dt><dd>{{ data_get($businessSummary, 'assessment.policy_review') === 'manual_review_required' ? '需要人工确认' : '—' }}</dd>
                        <dt>查询时间</dt><dd>{{ optional($cancellationContext->queried_at)->format('Y-m-d H:i:s') ?: '—' }}</dd>
                    </dl>
                    @if($cancellationContext->error_message)
                        <div class="alert alert-danger" style="margin-bottom:0">{{ $cancellationContext->error_code }}：{{ $cancellationContext->error_message }}</div>
                    @elseif(data_get($businessSummary, 'identity_check.email_match') === false)
                        <div class="alert alert-warning" style="margin-bottom:0">订单邮箱与 Ticket 请求人不一致，不能直接认定为同一客户。</div>
                    @else
                        <p class="text-muted" style="margin-bottom:0">业务系统结果与 Ticket 历史沟通分别保存；此核验不会修改订单、退款或 Decision Case 的人工结论。</p>
                    @endif
                @else
                    <p class="text-muted" style="margin-top:12px;margin-bottom:0">尚未查询业务系统，HelpDesk 的 solved 状态不代表订单已取消或退款已完成。</p>
                @endif
            </details></div>
        </div>

        @endif

        <div class="box box-warning">
            <div class="box-header with-border"><h3 class="box-title">Decision Case</h3></div>
            <div class="box-body">
                @if (!$case)
                    <p class="text-muted">尚未生成 Decision Case。可先运行案例构建命令。</p>
                @else
                    <form class="case-form" method="post" action="{{ admin_url('conversations/'.$ticket->id.'/cases/'.$case->id.'/review') }}">
                        @csrf
                        <div class="form-group"><label>审核结果</label><select class="form-control" name="review_status">@foreach(['confirmed' => '确认', 'corrected' => '修正后确认', 'rejected' => '拒绝'] as $value => $label)<option value="{{ $value }}" @selected($case->review_status === $value)>{{ $label }}</option>@endforeach</select></div>
                        <div class="form-group"><label>主要类别</label><input class="form-control" name="primary_category" value="{{ old('primary_category', $case->primary_category) }}"></div>
                        <div class="form-group"><label>问题类型</label><input class="form-control" name="primary_problem_type" value="{{ old('primary_problem_type', $case->primary_problem_type) }}"></div>
                        <div class="form-group"><label>风险</label><select class="form-control" name="case_risk">@foreach(['normal', 'high', 'critical'] as $risk)<option value="{{ $risk }}" @selected($case->case_risk === $risk)>{{ $risk }}</option>@endforeach</select></div>
                        <div class="form-group"><label>案例摘要</label><textarea class="form-control" rows="4" name="case_summary">{{ old('case_summary', $case->case_summary) }}</textarea></div>
                        <div class="form-group"><label>审核备注</label><textarea class="form-control" rows="2" name="review_notes">{{ old('review_notes', $case->review_notes) }}</textarea></div>
                        <button class="btn btn-warning"><i class="fa fa-check"></i> 保存案例审核</button>
                    </form>
                    <hr>
                    <p><strong>决策节点（{{ $case->nodes->count() }}）</strong></p>
                    @foreach($case->nodes as $node)
                        <details class="node-card" {{ $node->review_status === 'candidate' ? 'open' : '' }}>
                            <summary>#{{ $node->sequence }} {{ $node->request_summary }} <span class="label label-{{ $node->review_status === 'candidate' ? 'warning' : ($node->review_status === 'rejected' ? 'danger' : 'success') }}">{{ $reviewLabels[$node->review_status] ?? $node->review_status }}</span></summary>
                            <p style="margin-top:10px"><strong>证据：</strong></p>
                            <ul class="evidence-list">
                                @forelse($node->evidence as $evidence)
                                    <li><a href="#event-{{ $evidence->conversation_event_id }}">{{ $evidence->evidence_role }} · 消息 #{{ $evidence->conversation_event_id }}</a>@if($evidence->event?->body_text) — {{ Illuminate\Support\Str::limit($evidence->event->body_text, 80) }}@endif</li>
                                @empty<li class="text-danger">无关联证据</li>@endforelse
                            </ul>
                            <form class="node-form" method="post" action="{{ admin_url('conversations/'.$ticket->id.'/nodes/'.$node->id.'/review') }}">
                                @csrf
                                <div class="form-group"><label>审核结果</label><select class="form-control" name="review_status">@foreach(['confirmed' => '确认', 'corrected' => '修正后确认', 'rejected' => '拒绝'] as $value => $label)<option value="{{ $value }}" @selected($node->review_status === $value)>{{ $label }}</option>@endforeach</select></div>
                                <div class="form-group"><label>场景键</label><input class="form-control" name="scenario_key" value="{{ $node->scenario_key }}"></div>
                                <div class="form-group"><label>客户请求</label><textarea class="form-control" rows="2" name="request_summary" required>{{ $node->request_summary }}</textarea></div>
                                <div class="form-group"><label>观察到的决定</label><select class="form-control" name="observed_decision">@foreach(['accepted','rejected','conditional','alternative','escalated','unknown'] as $decision)<option value="{{ $decision }}" @selected($node->observed_decision === $decision)>{{ $decision }}</option>@endforeach</select></div>
                                <div class="form-group"><label>处理动作</label><textarea class="form-control" rows="2" name="observed_action">{{ $node->observed_action }}</textarea></div>
                                <div class="form-group"><label>理由</label><textarea class="form-control" rows="2" name="stated_reason">{{ $node->stated_reason }}</textarea></div>
                                <div class="form-group"><label>结果</label><textarea class="form-control" rows="2" name="observed_outcome">{{ $node->observed_outcome }}</textarea></div>
                                <div class="form-group"><label>审核备注</label><textarea class="form-control" rows="2" name="review_notes">{{ $node->review_notes }}</textarea></div>
                                <button class="btn btn-sm btn-primary">保存节点审核</button>
                            </form>
                        </details>
                    @endforeach
                @endif
            </div>
        </div>
    </aside>
</div>
