<div class="box box-primary">
    <div class="box-header with-border">
        <h3 class="box-title">站点</h3>
        <a class="btn btn-primary btn-sm pull-right" href="{{ admin_url('commerce-sites/create') }}"><i class="fa fa-plus"></i> 新增站点</a>
    </div>
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover">
            <thead><tr><th>名称</th><th>对外品牌</th><th>标识</th><th>站点公开地址</th><th>客服邮箱</th><th>Admin API 地址</th><th>状态</th><th></th></tr></thead>
            <tbody>
            @forelse($sites as $site)
                <tr>
                    <td>{{ $site->name }}</td>
                    <td>{{ $site->brand_name }}</td>
                    <td><code>{{ $site->site_key }}</code></td>
                    <td>{{ $site->public_base_url ?: '—' }}</td>
                    <td>{{ $site->customer_service_email ?: '—' }}</td>
                    <td>{{ $site->admin_api_url }}</td>
                    <td><span class="label label-{{ $site->enabled ? 'success' : 'default' }}">{{ $site->enabled ? '启用' : '停用' }}</span></td>
                    <td class="text-right"><a class="btn btn-default btn-xs" href="{{ admin_url('commerce-sites/'.$site->id.'/edit') }}">编辑</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted" style="padding:40px">尚未配置站点。配置后即可读取问答。</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
