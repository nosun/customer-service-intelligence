<div class="box box-primary"><div class="box-body">
    <p>Seawave 多件确认首轮。先核对事实与沟通记录；客户回复由 HelpDesk 人工接续。</p>
    <table class="table table-striped"><thead><tr><th>订单</th><th>站点</th><th>邮件状态</th><th>备注状态</th><th>等待状态</th><th>处理依据</th></tr></thead><tbody>
    @foreach($rounds as $round)
        <tr><td><a href="{{ admin_url('multi-dress-rounds/'.$round->id) }}">{{ $round->order_number }}</a></td>
            <td>{{ $round->site_key }}</td><td>{{ $round->communication_state }}</td><td>{{ $round->note_state }}</td>
            <td>{{ $round->waiting_state }}</td><td>{{ data_get($round->assessment, 'reason') }}</td></tr>
    @endforeach
    </tbody></table>{{ $rounds->links() }}
</div></div>
