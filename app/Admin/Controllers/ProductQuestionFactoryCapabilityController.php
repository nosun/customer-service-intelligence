<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CommerceSite;
use App\Models\ProductQuestionFactoryCapability;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductQuestionFactoryCapabilityController extends Controller
{
    public function index(Request $request, Content $content): Content
    {
        $sites = CommerceSite::where('enabled', true)->orderBy('name')->get();
        $query = ProductQuestionFactoryCapability::with('site')->orderByDesc('question_count');
        if ($request->filled('site_id')) {
            $query->where('commerce_site_id', $request->integer('site_id'));
        }
        if ($request->filled('review_status')) {
            $query->where('review_status', $request->query('review_status'));
        }

        return $content
            ->title('问答工厂能力')
            ->description('一次确认一项工厂能力，覆盖一批产品问答')
            ->body(view('admin.product-question-factory-capabilities.index', [
                'sites' => $sites,
                'capabilities' => $query->paginate(30)->withQueryString(),
                'filters' => $request->query(),
            ]));
    }

    public function show(int $id, Content $content): Content
    {
        $capability = ProductQuestionFactoryCapability::with('site')->findOrFail($id);

        return $content
            ->title($capability->title)
            ->description($capability->site->name.' · '.$capability->question_count.' 条问答')
            ->body(view('admin.product-question-factory-capabilities.show', compact('capability')));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $capability = ProductQuestionFactoryCapability::findOrFail($id);
        $data = $request->validate([
            'review_status' => ['required', Rule::in(array_keys(ProductQuestionFactoryCapability::STATUSES))],
            'applicability' => ['nullable', 'string', 'max:10000'],
            'exceptions' => ['nullable', 'string', 'max:10000'],
            'fee_policy' => ['nullable', 'string', 'max:1000'],
            'lead_time_policy' => ['nullable', 'string', 'max:1000'],
            'factory_answer' => ['nullable', 'string', 'max:20000'],
            'review_notes' => ['nullable', 'string', 'max:10000'],
        ]);
        $capability->update([
            ...$data,
            'reviewed_by' => Admin::user()->id,
            'reviewed_at' => now(),
        ]);
        admin_toastr('工厂能力结论已保存。', 'success');

        return back();
    }
}
