@php($syncTime = $lastSyncedAt ? \Illuminate\Support\Carbon::parse($lastSyncedAt) : null)

<style>
    .chat-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
    .chat-summary .small-box { margin-bottom: 0; }
    .chat-filter-grid { display: grid; grid-template-columns: minmax(210px, 2fr) repeat(3, minmax(140px, 1fr)) auto auto; gap: 10px; align-items: end; }
    .chat-filter-grid label { display: block; color: #777; font-size: 12px; }
    .chat-title { font-weight: 600; color: #333; }
    .chat-preview { color: #666; margin-top: 4px; max-width: 620px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .chat-meta { color: #888; font-size: 12px; margin-top: 4px; }
    .chat-table td { vertical-align: middle !important; }
    @media (max-width: 1100px) { .chat-filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 700px) { .chat-summary, .chat-filter-grid { grid-template-columns: 1fr; } }
</style>

<div class="chat-summary">
    <div class="small-box bg-aqua"><div class="inner"><h3>{{ number_format($counts['all']) }}</h3><p>全部会话</p></div><div class="icon"><i class="fa fa-comments"></i></div></div>
    <div class="small-box bg-green"><div class="inner"><h3>{{ number_format($counts['with_user_input']) }}</h3><p>包含用户输入</p></div><div class="icon"><i class="fa fa-user"></i></div></div>
    <div class="small-box bg-yellow"><div class="inner"><h3>{{ number_format($counts['with_ticket']) }}</h3><p>已关联 Ticket</p></div><div class="icon"><i class="fa fa-link"></i></div></div>
</div>

<div class="box box-primary">
    <div class="box-body">
        <form method="get" action="{{ admin_url('chatbot-chats') }}" class="chat-filter-grid">
            <div><label>关键词</label><input class="form-control" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="Chat ID、User ID 或消息内容"></div>
            <div><label>Story</label><select class="form-control" name="story_id"><option value="">全部</option>@foreach($stories as $story)<option value="{{ $story->id }}" @selected((string) ($filters['story_id'] ?? '') === (string) $story->id)>{{ $story->name ?: $story->id }}</option>@endforeach</select></div>
            <div><label>来源</label><select class="form-control" name="source"><option value="">全部</option>@foreach($sources as $source)<option value="{{ $source }}" @selected(($filters['source'] ?? '') === $source)>{{ $source }}</option>@endforeach</select></div>
            <div><label>保留状态</label><select class="form-control" name="retention_status"><option value="">全部</option>@foreach($retentionStatuses as $status)<option value="{{ $status }}" @selected(($filters['retention_status'] ?? '') === $status)>{{ $status }}</option>@endforeach</select></div>
            <div class="checkbox" style="margin:0 0 7px"><label><input type="checkbox" name="with_user_input" value="1" @checked(!empty($filters['with_user_input']))> 仅用户有发言</label></div>
            <div><button class="btn btn-primary"><i class="fa fa-search"></i> 筛选</button> <a class="btn btn-default" href="{{ admin_url('chatbot-chats') }}">清空</a></div>
        </form>
    </div>
</div>

<div class="box">
    <div class="box-header with-border">
        <h3 class="box-title">{{ number_format($chats->total()) }} 条结果</h3>
        @if($syncTime)<span class="pull-right text-muted" title="{{ $syncTime->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}">ChatBot checked {{ $syncTime->diffForHumans() }}</span>@endif
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover chat-table">
            <thead><tr><th>会话</th><th>Story / 来源</th><th>用户</th><th>状态</th><th>开始时间</th></tr></thead>
            <tbody>
            @forelse($chats as $chat)
                @php($firstUserMessage = $chat->events->first()?->body_text)
                <tr>
                    <td>
                        <a class="chat-title" href="{{ admin_url('chatbot-chats/'.$chat->id) }}">{{ $chat->external_id }}</a>
                        <div class="chat-preview">{{ $firstUserMessage ?: '没有可展示的用户消息' }}</div>
                        <div class="chat-meta">{{ $chat->events_count }} 条事件 · API message count {{ $chat->message_count }}</div>
                    </td>
                    <td>{{ $chat->story?->name ?: $chat->external_story_id ?: 'Unknown Story' }}<div class="chat-meta">{{ $chat->source ?: 'unknown' }}</div></td>
                    <td>{{ data_get($chat->user_snapshot, 'name') ?: data_get($chat->user_snapshot, 'email') ?: $chat->external_user_id ?: '匿名用户' }}</td>
                    <td><span class="label label-default">{{ $chat->retention_status }}</span>@if($chat->ticket_links_count)<div class="chat-meta"><i class="fa fa-link"></i> {{ $chat->ticket_links_count }} Ticket</div>@endif</td>
                    <td>@if($chat->started_at)<time title="{{ $chat->started_at->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}">{{ $chat->started_at->diffForHumans() }}</time>@else — @endif</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted" style="padding:40px">没有符合条件的 Chat</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($chats->hasPages())<div class="box-footer clearfix">{{ $chats->links() }}</div>@endif
</div>
