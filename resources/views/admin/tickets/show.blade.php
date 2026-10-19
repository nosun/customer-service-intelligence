@php
    $case = $ticket->decisionCase;
    $draft = $ticket->drafts->first();
    $ticketWorkItems = $ticket->runtimeCaseSource?->customerCase?->workItems ?? collect();
    $latestTicketWorkItem = $ticketWorkItems->first();
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

        <div class="box box-success">
            <div class="box-header with-border"><h3 class="box-title">回复草稿</h3></div>
            <div class="box-body" style="border-bottom:1px solid #eee">
                <form method="post" action="{{ admin_url('conversations/'.$ticket->id.'/generate-draft') }}">
                    @csrf
                    <button class="btn btn-primary" @disabled($latestTicketWorkItem && in_array($latestTicketWorkItem->status, ['queued', 'running'], true))>
                        <i class="fa fa-magic"></i> 通过 n8n 生成 AI Draft
                    </button>
                    <span class="text-muted" style="margin-left:10px">shadow 模式：只生成本地草稿，不发送、不执行业务动作。</span>
                </form>
                @if($latestTicketWorkItem)
                    <hr>
                    <dl class="detail-kv">
                        <dt>WorkItem</dt><dd>{{ $latestTicketWorkItem->work_item_key }}</dd>
                        <dt>运行状态</dt><dd>{{ $latestTicketWorkItem->status }}@if(data_get($latestTicketWorkItem->result, 'draft_status')) / {{ data_get($latestTicketWorkItem->result, 'draft_status') }}@endif</dd>
                        <dt>工作流</dt><dd>{{ $latestTicketWorkItem->workflow_key }}@{{ $latestTicketWorkItem->workflow_version }}</dd>
                        <dt>尝试次数</dt><dd>{{ $latestTicketWorkItem->attempt_count }} / {{ $latestTicketWorkItem->max_attempts }}</dd>
                    </dl>
                    @if($latestTicketWorkItem->error_message)<div class="alert alert-danger" style="margin-bottom:0">{{ $latestTicketWorkItem->error_message }}</div>@endif
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
                @endif
            </div>
            <form method="post" action="{{ admin_url('conversations/'.$ticket->id.'/draft') }}">
                @csrf
                <div class="box-body">
                    <div class="alert alert-info"><i class="fa fa-info-circle"></i> 当前只保存到本地，不会发送至 HelpDesk。@if($draft) 当前内容来源：{{ $draft->source }}，状态：{{ $draft->status }}。@endif</div>
                    <div class="form-group"><label>草稿内容</label><textarea class="form-control" rows="8" name="body" placeholder="输入回复或内部备注草稿">{{ old('body', $draft?->body) }}</textarea></div>
                    <div class="row">
                        <div class="col-sm-6"><div class="checkbox"><label><input type="checkbox" name="is_private" value="1" @checked(old('is_private', $draft?->is_private))> 私密备注</label></div></div>
                        <div class="col-sm-6"><select class="form-control" name="status_after_send"><option value="">发送后状态（暂不执行）</option>@foreach (['open', 'pending', 'onhold', 'solved', 'closed'] as $status)<option value="{{ $status }}" @selected(old('status_after_send', $draft?->status_after_send) === $status)>{{ $status }}</option>@endforeach</select></div>
                    </div>
                </div>
                <div class="box-footer"><button class="btn btn-success"><i class="fa fa-save"></i> 保存本地草稿</button>@if($draft)<span class="text-muted" style="margin-left:10px">上次保存：{{ $draft->updated_at->format('Y-m-d H:i:s') }}</span>@endif</div>
            </form>
            @if($draft?->source === 'n8n')
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

        <div class="box box-info">
            <div class="box-header with-border"><h3 class="box-title">取消处理核验</h3></div>
            <div class="box-body">
                <form method="post" action="{{ admin_url('conversations/'.$ticket->id.'/verify-cancellation') }}">
                    @csrf
                    <div class="form-group">
                        <label>订单号</label>
                        <input class="form-control" name="order_no" value="{{ old('order_no', $cancellationContext?->lookup_key) }}" placeholder="留空则从 Ticket 自动提取">
                    </div>
                    <button class="btn btn-info"><i class="fa fa-search"></i> 只读查询 console-new</button>
                </form>

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
            </div>
        </div>

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
