<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnalysis;
use App\Models\ProductQuestionPolicyCandidate;
use App\Services\ProductQuestions\ProductQuestionPolicyPrompt;
use App\Services\ProductQuestions\ProductQuestionPolicyReviewer;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductQuestionPolicyController extends Controller
{
    public function index(Request $request, Content $content): Content
    {
        $sites = CommerceSite::query()->where('enabled', true)->orderBy('name')->get();
        $query = ProductQuestionPolicyCandidate::query()
            ->with(['site', 'run'])
            ->whereHas('run', fn ($runs) => $runs->whereIn('status', ['completed', 'completed_with_errors']))
            ->latest('id');
        if (! $request->boolean('all_versions')) {
            $query->whereHas('run', fn ($runs) => $runs->where('prompt_version', ProductQuestionPolicyPrompt::VERSION));
        }
        if ($request->filled('site_id')) {
            $query->where('commerce_site_id', $request->integer('site_id'));
        }
        foreach (['review_status', 'category'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        return $content
            ->title('问答回复策略')
            ->description('按场景审核，不需要逐条审核历史问答')
            ->body(view('admin.product-question-policies.index', [
                'sites' => $sites,
                'candidates' => $query->paginate(30)->withQueryString(),
                'filters' => $request->query(),
                'currentVersion' => ProductQuestionPolicyPrompt::VERSION,
            ]));
    }

    public function show(int $id, Content $content): Content
    {
        $candidate = ProductQuestionPolicyCandidate::with(['site', 'run', 'knowledgeArticle'])->findOrFail($id);
        $evidence = ProductQuestion::query()
            ->where('commerce_site_id', $candidate->commerce_site_id)
            ->whereIn('id', $candidate->evidence_question_ids)
            ->orderByDesc('remote_created_at')
            ->get();

        return $content
            ->title($candidate->title)
            ->description($candidate->site->name.' · '.ProductQuestionAnalysis::CATEGORIES[$candidate->category])
            ->body(view('admin.product-question-policies.show', compact('candidate', 'evidence')));
    }

    public function review(Request $request, int $id, ProductQuestionPolicyReviewer $reviewer): RedirectResponse
    {
        $candidate = ProductQuestionPolicyCandidate::with(['site', 'run'])->findOrFail($id);
        $data = $request->validate([
            'review_status' => ['required', Rule::in(['confirmed', 'rejected'])],
            'scope' => ['nullable', Rule::in(['site', 'global'])],
            'review_notes' => ['nullable', 'string', 'max:10000'],
        ]);
        if ($candidate->review_status === 'confirmed' && $candidate->knowledge_article_id !== null) {
            if ($data['review_status'] !== 'confirmed') {
                return back()->withErrors(['review_status' => '已发布策略不能在这里直接驳回；请通过知识版本流程废弃。']);
            }
            admin_toastr('该策略已经发布，无需重复操作。', 'info');

            return back();
        }
        $reviewer->review(
            $candidate,
            $data['review_status'],
            $data['review_notes'] ?? null,
            $data['scope'] ?? $candidate->scope,
            Admin::user()->id,
        );
        admin_toastr($data['review_status'] === 'confirmed' ? '策略已确认并发布为 verified 知识。' : '策略候选已驳回。', 'success');

        return back();
    }
}
