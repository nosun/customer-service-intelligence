<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CommerceSite;
use Encore\Admin\Layout\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommerceSiteController extends Controller
{
    public function index(Content $content): Content
    {
        return $content
            ->title('站点配置')
            ->description('tradeManager Admin API')
            ->body(view('admin.commerce-sites.index', [
                'sites' => CommerceSite::query()->orderBy('name')->get(),
            ]));
    }

    public function create(Content $content): Content
    {
        return $this->form($content, new CommerceSite);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['api_token'] = trim((string) $data['api_token']);
        $data['brand_name'] = trim($data['brand_name']);
        $data['public_base_url'] = rtrim($data['public_base_url'], '/');
        $data['customer_service_email'] = $this->nullableEmail($data['customer_service_email'] ?? null);
        $data['public_phone'] = $this->nullableText($data['public_phone'] ?? null);
        $data['try_on_policy'] = $this->nullableText($data['try_on_policy'] ?? null);
        $data['physical_store_info'] = $this->nullableText($data['physical_store_info'] ?? null);
        CommerceSite::create($data);
        admin_toastr('站点配置已保存。', 'success');

        return redirect(admin_url('commerce-sites'));
    }

    public function edit(CommerceSite $commerceSite, Content $content): Content
    {
        return $this->form($content, $commerceSite);
    }

    public function update(Request $request, CommerceSite $commerceSite): RedirectResponse
    {
        $data = $this->validated($request, $commerceSite);
        $data['brand_name'] = trim($data['brand_name']);
        $data['public_base_url'] = rtrim($data['public_base_url'], '/');
        $data['customer_service_email'] = $this->nullableEmail($data['customer_service_email'] ?? null);
        $data['public_phone'] = $this->nullableText($data['public_phone'] ?? null);
        $data['try_on_policy'] = $this->nullableText($data['try_on_policy'] ?? null);
        $data['physical_store_info'] = $this->nullableText($data['physical_store_info'] ?? null);
        if (trim((string) ($data['api_token'] ?? '')) === '') {
            unset($data['api_token']);
        } else {
            $data['api_token'] = trim($data['api_token']);
        }
        $commerceSite->update($data);
        admin_toastr('站点配置已更新。', 'success');

        return redirect(admin_url('commerce-sites'));
    }

    private function form(Content $content, CommerceSite $site): Content
    {
        return $content
            ->title($site->exists ? '编辑站点' : '新增站点')
            ->description('API Token 加密保存且不会回显')
            ->body(view('admin.commerce-sites.form', compact('site')));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?CommerceSite $site = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'brand_name' => ['required', 'string', 'max:100'],
            'site_key' => [
                'required',
                'alpha_dash:ascii',
                'max:50',
                Rule::unique('commerce_sites', 'site_key')->ignore($site?->id),
            ],
            'admin_api_url' => ['required', 'url:http,https', 'max:500'],
            'public_base_url' => ['required', 'url:http,https', 'max:500'],
            'customer_service_email' => ['nullable', 'email:rfc', 'max:254'],
            'operates_online_only' => ['nullable', 'boolean'],
            'live_chat_enabled' => ['nullable', 'boolean'],
            'public_phone' => ['nullable', 'string', 'max:100'],
            'try_on_policy' => ['nullable', Rule::in(['not_offered', 'available', 'appointment_only'])],
            'physical_store_info' => ['nullable', 'string', 'max:2000'],
            'api_token' => [$site?->exists ? 'nullable' : 'required', 'string', 'max:4096'],
            'enabled' => ['nullable', 'boolean'],
        ]) + ['enabled' => false, 'operates_online_only' => null, 'live_chat_enabled' => null];
    }

    private function nullableEmail(mixed $value): ?string
    {
        $email = trim((string) $value);

        return $email === '' ? null : $email;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
