@include('admin.follow-ups.messages')
<p class="text-muted">要持续处理的事情记在这里。默认明天 10:00 再跟进，可随时调整；所有时间均为北京时间。</p>

<div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">记一件要跟进的事</h3></div><div class="box-body">
    @if($ticket)
        <p>关联 <a href="{{ admin_url('conversations/'.$ticket->id) }}">Ticket {{ $ticket->external_short_id ?: $ticket->id }}</a>。请先检查下方是否已有同一问题。</p>
    @endif
    <form method="post" action="{{ admin_url('follow-ups') }}">
        @csrf
        @if($ticket)<input type="hidden" name="conversation_id" value="{{ $ticket->id }}">@endif
        <div class="form-group"><label for="follow-up-title">要跟进什么 <span class="text-danger">*</span></label><input id="follow-up-title" class="form-control" name="title" required maxlength="255" placeholder="例如：订单 DA123 逾期，明天确认工厂是否发货" value="{{ old('title', $ticket ? '跟进：'.mb_substr($ticket->subject ?: 'Ticket '.$ticket->external_short_id, 0, 200) : '') }}"></div>
        <div class="form-group"><label for="follow-up-note">当前情况 / 下一步（选填）</label><textarea id="follow-up-note" class="form-control" name="note" rows="2" maxlength="20000" placeholder="已查到什么、还在等谁、接下来做什么">{{ old('note') }}</textarea></div>
        <div class="row">
            <div class="col-sm-5 form-group"><label for="follow-up-due">下次跟进</label><input id="follow-up-due" class="form-control" type="datetime-local" name="next_follow_up_at" value="{{ old('next_follow_up_at', $defaultDue) }}"></div>
            <div class="col-sm-7 form-group"><label>&nbsp;</label><div><label style="font-weight:normal"><input type="checkbox" name="urgent" value="1" @checked(old('urgent'))> 紧急，需要优先处理</label></div></div>
        </div>
        <details style="margin-bottom:15px" @if(old('reference') || old('owner')) open @endif><summary style="cursor:pointer">补充关联信息（选填）</summary>
            <div class="form-group" style="margin-top:10px"><label>站点 / 订单号 / 运单号 / 链接</label><input class="form-control" name="reference" maxlength="1000" value="{{ old('reference', $ticket ? trim(($ticket->site_key ?? '').' '.($ticket->linked_order_number ?? '')) : '') }}"></div>
            <div class="form-group"><label>负责人</label><input class="form-control" name="owner" maxlength="100" value="{{ old('owner') }}" placeholder="默认由当前登录人负责"></div>
        </details>
        <button class="btn btn-primary">加入跟进</button>
    </form>
</div></div>

<div class="box"><div class="box-body">
    <form method="get">
        @if($ticket)<input type="hidden" name="conversation_id" value="{{ $ticket->id }}">@endif
        <div class="row">
            <div class="col-sm-3 form-group"><label>查看</label><select class="form-control" name="view">@foreach(\App\Models\FollowUp::FILTERS as $key => $label)<option value="{{ $key }}" @selected(($filters['view'] ?? 'open') === $key)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-sm-6 form-group"><label>搜索</label><input class="form-control" name="search" maxlength="200" value="{{ $filters['search'] ?? '' }}" placeholder="标题、订单号、运单号、负责人或 Ticket 编号"></div>
            <div class="col-sm-3 form-group"><label>&nbsp;</label><div><button class="btn btn-default">筛选</button> <a href="{{ admin_url('follow-ups') }}">全部事项</a></div></div>
        </div>
    </form>
</div><div class="box-body table-responsive no-padding"><table class="table table-hover">
    <thead><tr><th style="min-width:220px">事项 / 关联信息</th><th>状态 / 负责人</th><th style="min-width:220px">最近进展</th><th style="min-width:145px">下次跟进</th></tr></thead>
    <tbody>@forelse($items as $item)<tr>
        <td>@if($item->urgent)<span class="label label-danger">紧急</span> @endif<a href="{{ admin_url('follow-ups/'.$item->id) }}">#{{ $item->id }} {{ $item->title }}</a>
            <div class="text-muted" style="overflow-wrap:anywhere">{{ $item->reference }}</div>
            @if($item->conversation)<a href="{{ admin_url('conversations/'.$item->conversation_id) }}">Ticket {{ $item->conversation->external_short_id ?: $item->conversation_id }}</a>@endif
        </td>
        <td>{{ \App\Models\FollowUp::STATUSES[$item->status] }}<div class="text-muted">{{ $item->owner ?: '未分配' }}</div></td>
        <td style="white-space:pre-wrap;overflow-wrap:anywhere">{{ \Illuminate\Support\Str::limit($item->latest_note, 180) }}</td>
        <td>
            @if($item->next_follow_up_at)
                <span @class(['text-danger' => $item->status !== 'closed' && $item->next_follow_up_at->isPast()])>{{ $item->next_follow_up_at->timezone(\App\Models\FollowUp::TIMEZONE)->format('Y-m-d H:i') }}</span>
                @if($item->status !== 'closed' && $item->next_follow_up_at->isPast())<div class="text-danger">已到期</div>@endif
            @else<span class="text-muted">未安排时间</span>@endif
        </td>
    </tr>@empty<tr><td colspan="4" class="text-center text-muted" style="padding:30px">当前没有符合条件的跟进事项。</td></tr>@endforelse</tbody>
</table></div><div class="box-footer">共 {{ $items->total() }} 项 {{ $items->links() }}</div></div>
