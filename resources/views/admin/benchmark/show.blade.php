@php
    $statusLabels = ['pending' => '待标注', 'annotated' => '已标注', 'adjudicated' => '已裁决'];
    $statusColors = ['pending' => 'warning', 'annotated' => 'info', 'adjudicated' => 'success'];
    $stepsByTrigger = $case->steps->keyBy('trigger_event_id');
    $observedReplyStepNumbers = [];
    foreach ($case->steps as $caseStep) {
        foreach ($caseStep->observed_reply_event_ids as $eventId) {
            $observedReplyStepNumbers[$eventId][] = $caseStep->sequence;
        }
    }
@endphp

<style>
    .stage-layout { display: grid; grid-template-columns: minmax(0, 3fr) minmax(340px, 2fr); gap: 18px; align-items: start; }
    .stage-event { border-left: 4px solid #d2d6de; margin-bottom: 12px; padding: 12px 14px; background: #fff; box-shadow: 0 1px 1px rgba(0,0,0,.08); }
    .stage-event.client, .stage-event.customer { border-left-color: #3c8dbc; }
    .stage-event.agent { border-left-color: #00a65a; }
    .stage-event.private { background: #fff8e6; border-left-color: #f39c12; }
    .stage-event-head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
    .stage-event-body { overflow-wrap: anywhere; line-height: 1.55; }
    .stage-meta { color: #777; font-size: 12px; }
    .stage-card { border-left: 4px solid #3c8dbc; padding: 12px; margin-bottom: 12px; background: #fff; }
    .stage-card h4 { margin-top: 0; }
    .stage-form .checkbox-list { max-height: 180px; overflow-y: auto; border: 1px solid #ddd; padding: 8px 12px; }
    .stage-timeline { background: #f5f5f5; padding: 12px; }
    @media (max-width: 1000px) { .stage-layout { grid-template-columns: 1fr; } }
</style>

<p>
    <a href="{{ admin_url('benchmark-cases') }}"><i class="fa fa-arrow-left"></i> 返回案例列表</a>
    @if ($nextCase)<a class="btn btn-default btn-xs pull-right" href="{{ admin_url('benchmark-cases/'.$nextCase->id) }}">下一张 Ticket <i class="fa fa-arrow-right"></i></a>@endif
</p>

@if ($errors->any())
    <div class="alert alert-danger"><strong>保存失败：</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<div class="alert alert-info">
    <strong>第一步：切分决策阶段。</strong>
    这里可以看到完整历史回复，用于判断阶段边界。真正标注阶段时，系统只显示截至触发客户消息的上下文，并隐藏后续客服答案。
</div>

<div class="stage-layout">
    <main class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">Ticket 完整时间线</h3>
            <span class="pull-right text-muted">{{ $ticket->external_short_id ?: $ticket->external_id }} · {{ $ticket->events->count() }} 条事件</span>
        </div>
        <div class="box-body"><strong>{{ $ticket->subject ?: '无主题' }}</strong></div>
        <div class="stage-timeline">
            @foreach ($ticket->events as $event)
                <article id="event-{{ $event->id }}" class="stage-event {{ $event->author_type }} {{ $event->is_private ? 'private' : '' }}">
                    <header class="stage-event-head">
                        <div>
                            <strong>#{{ $event->id }} · {{ $event->author_name ?: ucfirst($event->author_type ?: 'system') }}</strong>
                            <span class="label label-default">{{ $event->author_type ?: $event->event_type }}</span>
                            @if ($stepsByTrigger->has($event->id))<span class="label label-primary">阶段 {{ $stepsByTrigger[$event->id]->sequence }} 触发点</span>@endif
                            @foreach ($observedReplyStepNumbers[$event->id] ?? [] as $sequence)<span class="label label-success">阶段 {{ $sequence }} 历史回复</span>@endforeach
                        </div>
                        <time class="stage-meta">{{ optional($event->occurred_at)->format('Y-m-d H:i:s') ?: '时间未知' }}</time>
                    </header>
                    @if ($event->body_text)<div class="stage-event-body">{!! nl2br(e($event->body_text)) !!}</div>@elseif ($event->status_value)<div>状态变更为：{{ $event->status_value }}</div>@else<div class="text-muted">{{ $event->event_type }}</div>@endif
                </article>
            @endforeach
        </div>
    </main>

    <aside>
        <div class="box box-solid">
            <div class="box-header with-border"><h3 class="box-title">已切分阶段（{{ $case->steps->count() }}）</h3></div>
            <div class="box-body">
                @forelse ($case->steps as $step)
                    <section class="stage-card">
                        <h4>阶段 {{ $step->sequence }}：{{ $step->title }}</h4>
                        <p>
                            <span class="label label-{{ $statusColors[$step->status] ?? 'default' }}">{{ $statusLabels[$step->status] ?? $step->status }}</span>
                            <span class="text-muted">触发消息 #{{ $step->trigger_event_id }} · {{ $step->annotations_count }} 份标注</span>
                        </p>
                        @if ($step->stage_end_reason)<p><strong>结束原因：</strong>{{ $step->stage_end_reason }}</p>@endif
                        <a class="btn btn-primary btn-sm" href="{{ admin_url('benchmark-cases/'.$case->id.'/steps/'.$step->id) }}"><i class="fa fa-pencil"></i> 标注这个阶段</a>

                        @if ($step->annotations_count === 0)
                            <details style="margin-top:10px">
                                <summary>调整阶段边界</summary>
                                <form method="post" action="{{ admin_url('benchmark-cases/'.$case->id.'/steps/'.$step->id) }}" style="margin-top:10px">
                                    @csrf @method('PUT')
                                    @include('admin.benchmark.step-boundary-fields', ['editingStep' => $step])
                                    <button class="btn btn-default btn-sm">保存边界</button>
                                </form>
                            </details>
                        @endif
                    </section>
                @empty
                    <p class="text-muted">还没有阶段。请从下方建立第一个决策阶段。</p>
                @endforelse
            </div>
        </div>

        <div class="box box-warning stage-form">
            <div class="box-header with-border"><h3 class="box-title">新增决策阶段</h3></div>
            <form method="post" action="{{ admin_url('benchmark-cases/'.$case->id.'/steps') }}">
                @csrf
                <div class="box-body">
                    @include('admin.benchmark.step-boundary-fields', ['editingStep' => null])
                </div>
                <div class="box-footer"><button class="btn btn-warning"><i class="fa fa-plus"></i> 建立阶段</button></div>
            </form>
        </div>
    </aside>
</div>
