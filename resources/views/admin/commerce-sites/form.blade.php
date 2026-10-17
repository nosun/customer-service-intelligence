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
                <label>经营方式</label>
                <select class="form-control" name="operates_online_only">
                    <option value="" @selected(old('operates_online_only', $site->operates_online_only) === null)>未知，回复时不作判断</option>
                    <option value="1" @selected((string) old('operates_online_only', $site->operates_online_only) === '1')>仅在线经营</option>
                    <option value="0" @selected((string) old('operates_online_only', $site->operates_online_only) === '0')>存在其他经营方式</option>
                </select>
            </div>
            <div class="form-group">
                <label>Live Chat</label>
                <select class="form-control" name="live_chat_enabled">
                    <option value="" @selected(old('live_chat_enabled', $site->live_chat_enabled) === null)>未知，回复时不作判断</option>
                    <option value="1" @selected((string) old('live_chat_enabled', $site->live_chat_enabled) === '1')>已启用</option>
                    <option value="0" @selected((string) old('live_chat_enabled', $site->live_chat_enabled) === '0')>未启用</option>
                </select>
            </div>
            <div class="form-group">
                <label>公开客服电话</label>
                <input class="form-control" name="public_phone" value="{{ old('public_phone', $site->public_phone) }}" maxlength="100" placeholder="留空表示未核实">
            </div>
            <div class="form-group">
                <label>试穿政策</label>
                <select class="form-control" name="try_on_policy">
                    <option value="" @selected(old('try_on_policy', $site->try_on_policy) === null)>未知，回复时不作判断</option>
                    <option value="not_offered" @selected(old('try_on_policy', $site->try_on_policy) === 'not_offered')>不提供试穿</option>
                    <option value="available" @selected(old('try_on_policy', $site->try_on_policy) === 'available')>提供试穿</option>
                    <option value="appointment_only" @selected(old('try_on_policy', $site->try_on_policy) === 'appointment_only')>仅预约试穿</option>
                </select>
            </div>
            <div class="form-group">
                <label>门店 / 展厅说明</label>
                <textarea class="form-control" name="physical_store_info" rows="3" maxlength="2000" placeholder="只填写已经核实、可对客户公开的信息">{{ old('physical_store_info', $site->physical_store_info) }}</textarea>
                <p class="help-block">这些字段是联系、门店和试穿回复的事实来源；留空时 AI Draft 必须说明需要核实。</p>
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
