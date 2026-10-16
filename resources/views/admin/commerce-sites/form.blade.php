<div class="box box-primary">
    <form method="post" action="{{ $site->exists ? admin_url('commerce-sites/'.$site->id) : admin_url('commerce-sites') }}">
        @csrf
        @if($site->exists) @method('PUT') @endif
        <div class="box-body">
            @if($errors->any())
                <div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <div class="form-group">
                <label>站点名称</label>
                <input class="form-control" name="name" value="{{ old('name', $site->name) }}" required maxlength="100" placeholder="DressAfford">
            </div>
            <div class="form-group">
                <label>对外品牌名称</label>
                <input class="form-control" name="brand_name" value="{{ old('brand_name', $site->brand_name) }}" required maxlength="100" placeholder="Dress Afford">
                <p class="help-block">供回复策略通过 <code>@{{ site.brand_name }}</code> 引用，不应在跨站点策略中写死品牌。</p>
            </div>
            <div class="form-group">
                <label>站点标识</label>
                <input class="form-control" name="site_key" value="{{ old('site_key', $site->site_key) }}" required maxlength="50" placeholder="dressafford">
                <p class="help-block">只允许字母、数字、短横线和下划线，且不可重复。</p>
            </div>
            <div class="form-group">
                <label>Admin API 地址</label>
                <input class="form-control" type="url" name="admin_api_url" value="{{ old('admin_api_url', $site->admin_api_url) }}" required maxlength="500" placeholder="https://example.com/api/admin">
            </div>
            <div class="form-group">
                <label>站点公开地址</label>
                <input class="form-control" type="url" name="public_base_url" value="{{ old('public_base_url', $site->public_base_url) }}" required maxlength="500" placeholder="https://www.example.com">
                <p class="help-block">用于生成当前站点的政策页链接，例如 <code>@{{ site.public_base_url }}/shipping-info.html</code>。</p>
            </div>
            <div class="form-group">
                <label>客服邮箱</label>
                <input class="form-control" type="email" name="customer_service_email" value="{{ old('customer_service_email', $site->customer_service_email) }}" maxlength="254" placeholder="support@example.com">
                <p class="help-block">供回复策略通过 <code>@{{ site.customer_service_email }}</code> 引用；未配置时不得自动生成客服邮箱。</p>
            </div>
            <div class="form-group">
                <label>API Token</label>
                <input class="form-control" type="password" name="api_token" value="" {{ $site->exists ? '' : 'required' }} autocomplete="new-password" maxlength="4096">
                <p class="help-block">{{ $site->exists ? '留空表示保留现有 Token。' : 'Token 将加密保存，保存后不再回显。' }}</p>
            </div>
            <div class="checkbox"><label><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $site->exists ? $site->enabled : true))> 启用</label></div>
        </div>
        <div class="box-footer">
            <button class="btn btn-primary" type="submit">保存</button>
            <a class="btn btn-default" href="{{ admin_url('commerce-sites') }}">取消</a>
        </div>
    </form>
</div>
