@php
    $tz = \App\Models\FollowUp::TIMEZONE;
    $fieldLabels = ['title' => '标题', 'status' => '状态', 'urgent' => '紧急', 'reference' => '关联信息', 'owner' => '负责人', 'conversation_id' => '关联 Ticket（本地 ID）', 'next_follow_up_at' => '下次跟进'];
@endphp
@include('admin.follow-ups.messages')
<p><a href="{{ admin_url('follow-ups') }}">← 返回跟进台账</a>@if($item->conversation) · <a href="{{ admin_url('conversations/'.$item->conversation_id) }}">查看 Ticket {{ $item->conversation->external_short_id ?: $item->conversation_id }}</a>@endif</p>
<div class="box box-primary"><div class="box-header with-border"><h3 class="box-title">{{ $item->title }}</h3>
    <span class="label label-default">{{ \App\Models\FollowUp::STATUSES[$item->status] }}</span>@if($item->urgent) <span class="label label-danger">紧急</span>@endif
</div><div class="box-body">
    <p class="text-muted" style="overflow-wrap:anywhere">{{ $item->reference }}@if($item->owner) · 负责人：{{ $item->owner }}@endif</p>
    @if($item->latest_note)<div style="white-space:pre-wrap;overflow-wrap:anywhere;margin-bottom:15px">{{ $item->latest_note }}</div>@endif
    @if($item->closed_at)<p class="text-muted">结束于 {{ $item->closed_at->timezone($tz)->format('Y-m-d H:i') }}（北京时间）</p>@endif
    <form method="post" action="{{ admin_url('follow-ups/'.$item->id) }}">
        @csrf
        <input type="hidden" name="version" value="{{ $item->version }}">
        <div class="form-group"><label for="progress-note">记录新进展</label><textarea id="progress-note" class="form-control" name="note" rows="3" maxlength="20000" placeholder="这次做了什么？目前等谁？下一步做什么？">{{ old('note') }}</textarea></div>
        <div class="row">
            <div class="col-sm-4 form-group"><label>状态</label><select class="form-control" name="status">@foreach(\App\Models\FollowUp::STATUSES as $key => $label)<option value="{{ $key }}" @selected(old('status', $item->status) === $key)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-sm-5 form-group"><label>下次跟进（北京时间）</label><input class="form-control" name="next_follow_up_at" type="datetime-local" value="{{ old('next_follow_up_at', $item->next_follow_up_at?->timezone($tz)->format('Y-m-d\TH:i')) }}"></div>
            <div class="col-sm-3 form-group"><label>&nbsp;</label><div><input type="hidden" name="urgent" value="0"><label style="font-weight:normal"><input type="checkbox" name="urgent" value="1" @checked(old('urgent', $item->urgent))> 紧急</label></div></div>
        </div>
        <details style="margin-bottom:15px"><summary style="cursor:pointer">修改标题、关联信息或负责人</summary>
            <div class="form-group" style="margin-top:10px"><label>标题</label><input class="form-control" name="title" required maxlength="255" value="{{ old('title', $item->title) }}"></div>
            <div class="form-group"><label>站点 / 订单号 / 运单号 / 链接</label><input class="form-control" name="reference" maxlength="1000" value="{{ old('reference', $item->reference) }}"></div>
            <div class="form-group"><label>负责人</label><input class="form-control" name="owner" maxlength="100" value="{{ old('owner', $item->owner) }}"></div>
        </details>
        <button class="btn btn-primary">保存进展</button>
        <span class="text-muted" style="margin-left:8px">只更新状态或时间也可以。已结束的事项可重新打开。</span>
    </form>
</div></div>

<div class="box"><div class="box-header with-border"><h3 class="box-title">处理记录（{{ $entries->total() }}）</h3></div><div class="box-body">
    @foreach($entries as $entry)
        <article style="border-left:3px solid #d2d6de;padding:5px 15px;margin-bottom:20px">
            <p class="text-muted">{{ $entry->created_at->timezone($tz)->format('Y-m-d H:i:s') }} · {{ $entry->actor }}</p>
            @if($entry->note)<div style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $entry->note }}</div>@endif
            @if($entry->changes)<ul style="margin-top:8px;padding-left:18px">
                @foreach($entry->changes as $field => $change)
                    @php
                        $display = function ($value) use ($field, $tz) {
                            if ($value === null || $value === '') return '未设置';
                            if ($field === 'status') return \App\Models\FollowUp::STATUSES[$value] ?? $value;
                            if ($field === 'urgent') return $value ? '是' : '否';
                            if ($field === 'next_follow_up_at') return \Illuminate\Support\Carbon::parse($value)->timezone($tz)->format('Y-m-d H:i');
                            return $value;
                        };
                    @endphp
                    <li style="overflow-wrap:anywhere">{{ $fieldLabels[$field] ?? $field }}：{{ $display($change['before']) }} → {{ $display($change['after']) }}</li>
                @endforeach
            </ul>@endif
        </article>
    @endforeach
    {{ $entries->links() }}
</div></div>
