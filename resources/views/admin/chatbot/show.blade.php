@php
    $user = $chat->user_snapshot ?: [];
    $attributes = $chat->attributes ?: [];
@endphp

<style>
    .chat-detail { display: grid; grid-template-columns: minmax(0, 2fr) minmax(320px, 1fr); gap: 18px; }
    .chat-back { display: inline-block; margin-bottom: 12px; }
    .chat-event { max-width: 82%; margin-bottom: 14px; padding: 12px 15px; border-radius: 8px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.1); }
    .chat-event.user { margin-left: auto; border-right: 4px solid #3c8dbc; background: #eef7fc; }
    .chat-event.bot { margin-right: auto; border-left: 4px solid #00a65a; }
    .chat-event.system { margin-right: auto; border-left: 4px solid #aaa; background: #f7f7f7; }
    .chat-event-head { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 7px; }
    .chat-event-body { white-space: normal; overflow-wrap: anywhere; line-height: 1.55; }
    .chat-meta { color: #888; font-size: 12px; }
    .chat-kv { display: grid; grid-template-columns: 105px 1fr; gap: 7px 10px; }
    .chat-kv dt { color: #777; font-weight: 400; }
    .chat-kv dd { margin: 0; overflow-wrap: anywhere; }
    .chat-json { max-height: 260px; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 12px; }
    @media (max-width: 900px) { .chat-detail { grid-template-columns: 1fr; } .chat-event { max-width: 100%; } }
</style>

<a class="chat-back" href="{{ admin_url('chatbot-chats') }}"><i class="fa fa-arrow-left"></i> 返回 Chat 列表</a>

<div class="chat-detail">
    <main>
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title">消息时间线</h3><span class="pull-right text-muted">{{ $chat->events->count() }} 条事件</span></div>
            <div class="box-body" style="background:#f1f3f5">
                @forelse($chat->events as $event)
                    <article class="chat-event {{ in_array($event->author_type, ['user', 'bot'], true) ? $event->author_type : 'system' }}">
                        <header class="chat-event-head">
                            <div><strong>{{ $event->author_type === 'user' ? 'Customer' : ($event->author_type === 'bot' ? 'Bot' : 'System') }}</strong> <span class="label label-default">{{ $event->event_type }}</span></div>
                            <time class="chat-meta">{{ optional($event->occurred_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?: '时间未知' }}</time>
                        </header>
                        @if($event->body_text)<div class="chat-event-body">{!! nl2br(e($event->body_text)) !!}</div>@else<div class="chat-event-body text-muted">没有文本内容</div>@endif
                        @if($event->score !== null)<div class="chat-meta" style="margin-top:7px">匹配分数：{{ $event->score }}</div>@endif
                    </article>
                @empty
                    <p class="text-center text-muted">此 Chat 尚无消息事件。</p>
                @endforelse
            </div>
        </div>
    </main>

    <aside>
        <div class="box box-solid">
            <div class="box-header with-border"><h3 class="box-title">会话信息</h3></div>
            <div class="box-body"><dl class="chat-kv">
                <dt>Chat ID</dt><dd>{{ $chat->external_id }}</dd>
                <dt>Story</dt><dd>{{ $chat->story?->name ?: $chat->external_story_id ?: 'Unknown' }}</dd>
                <dt>来源</dt><dd>{{ $chat->source ?: 'unknown' }}</dd>
                <dt>开始时间</dt><dd>{{ optional($chat->started_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?: '—' }}</dd>
                <dt>外部更新</dt><dd>{{ optional($chat->updated_at_external)->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?: '—' }}</dd>
                <dt>本地同步</dt><dd>{{ optional($chat->synced_at)->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?: '—' }}</dd>
                <dt>保留状态</dt><dd>{{ $chat->retention_status }}</dd>
                <dt>消息数</dt><dd>{{ $chat->message_count }} API messages / {{ $chat->events->count() }} events</dd>
            </dl></div>
        </div>

        <div class="box box-solid">
            <div class="box-header with-border"><h3 class="box-title">用户</h3></div>
            <div class="box-body"><dl class="chat-kv">
                <dt>User ID</dt><dd>{{ $chat->external_user_id ?: data_get($user, 'id') ?: '—' }}</dd>
                <dt>姓名</dt><dd>{{ data_get($user, 'name') ?: '—' }}</dd>
                <dt>邮箱</dt><dd>{{ data_get($user, 'email') ?: '—' }}</dd>
            </dl></div>
        </div>

        <div class="box box-solid">
            <div class="box-header with-border"><h3 class="box-title">会话属性</h3></div>
            <div class="box-body"><pre class="chat-json">{{ json_encode($attributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></div>
        </div>

        <div class="box box-solid">
            <div class="box-header with-border"><h3 class="box-title">关联 Ticket</h3></div>
            <div class="box-body">
                @forelse($chat->ticketLinks as $link)
                    <p><a href="{{ admin_url('conversations/'.$link->conversation_id) }}">{{ $link->conversation?->external_short_id ?: $link->conversation?->external_id ?: 'Ticket '.$link->conversation_id }}</a><br><span class="chat-meta">{{ $link->match_method }} · {{ $link->review_status }}@if($link->confidence !== null) · {{ $link->confidence }}@endif</span></p>
                @empty
                    <p class="text-muted">尚未关联 HelpDesk Ticket。</p>
                @endforelse
            </div>
        </div>
    </aside>
</div>
