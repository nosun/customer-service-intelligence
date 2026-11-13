<div class="box box-primary"><div class="box-body">
    <p><strong>邮件状态：</strong>{{ $round->communication_state }}　<strong>备注状态：</strong>{{ $round->note_state }}　<strong>等待状态：</strong>{{ $round->waiting_state }}</p>
    <p>首次发送：{{ $round->sent_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?? '尚未确认发送' }}；48小时截止：{{ $round->wait_until?->timezone('Asia/Shanghai')->format('Y-m-d H:i:s') ?? '未开始计时' }}</p>
    <p>人工接管：{{ $round->human_hold_reason ?? '无' }}；判断依据：{{ data_get($round->assessment, 'reason') }}</p>
    <h4>首信预览</h4>
    @if($mail)
        <p>{{ $mail['subject'] }}</p><pre style="white-space:pre-wrap">{{ $mail['body'] }}</pre>
        <p>此内容已完成草稿审核；实际是否发送以邮件状态为准。</p>
    @else
        <p>尚无审核通过的首信。请先核对事实与沟通记录。</p>
    @endif
    @if(!$round->human_hold_at)
        <form method="post" action="{{ admin_url('multi-dress-rounds/'.$round->id.'/hold') }}">@csrf
            <input type="hidden" name="version" value="{{ $round->version }}">
            <input name="reason" required maxlength="255" placeholder="接管原因"><button class="btn btn-warning">人工接管并停止后续执行</button>
        </form>
    @endif
    @if(in_array($round->communication_state, ['new', 'ready']) && !$round->human_hold_at)
        <form method="post" action="{{ admin_url('multi-dress-rounds/'.$round->id.'/draft') }}">@csrf<button class="btn btn-default">排队生成首信草稿</button></form>
    @endif
    @if($round->communication_state === 'sent' && $round->note_state !== 'recorded' && !$round->human_hold_at && config('customer_contacts.mode') === 'live' && config('customer_contacts.console_note_enabled'))
        <form method="post" action="{{ admin_url('multi-dress-rounds/'.$round->id.'/preview-note') }}">@csrf<button class="btn btn-default">预览 Console 内部备注追加</button></form>
    @endif
    <h4>执行记录及预览</h4>
    @foreach($round->effects as $effect)
        <p>{{ $effect->effect_kind }}：{{ $effect->state }} {{ $effect->error_code }}</p>
        @if($effect->state === 'previewed')
            <pre style="white-space:pre-wrap">{{ json_encode(data_get($effect->preview, 'diff'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            <form method="post" action="{{ admin_url('multi-dress-rounds/'.$round->id.'/effects/'.$effect->id.'/confirm') }}">@csrf
                <input type="hidden" name="preview_hash" value="{{ $effect->payload_hash }}"><button class="btn btn-primary">确认执行以上备注预览</button>
            </form>
        @endif
    @endforeach
    <h4>处理证据</h4><pre style="white-space:pre-wrap">{{ json_encode($round->source_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    <h4>操作历史</h4>
    @foreach($round->events as $event)<p>{{ $event->occurred_at }} {{ $event->actor }} {{ $event->event_type }}</p>@endforeach
</div></div>
