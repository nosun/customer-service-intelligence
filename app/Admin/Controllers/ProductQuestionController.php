<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CommerceProduct;
use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnalysis;
use App\Models\ProductQuestionDraftFeedback;
use App\Models\ProductQuestionDraftRequest;
use App\Services\N8n\N8nDraftWorkflowClient;
use App\Services\N8n\WorkflowRegistry;
use App\Services\ProductQuestions\N8nProductQuestionDraftService;
use App\Services\ProductQuestions\ProductQuestionDraftFeedbackRecorder;
use App\Services\ProductQuestions\ProductQuestionDraftInput;
use App\Services\TradeManager\ProductQuestionClient;
use App\Services\TradeManager\ProductQuestionSyncService;
use App\Services\TradeManager\TradeManagerApiException;
use App\Services\Work\ProductQuestionWorkItemManager;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ProductQuestionController extends Controller
{
    public function index(Request $request, Content $content): Content
    {
        $sites = CommerceSite::query()->where('enabled', true)->orderBy('name')->get();
        $site = $this->selectedSite($request, $sites);
        $query = ProductQuestion::query()->withoutReplay()->with('analysis')->where('remote_present', true);
        $site ? $query->where('commerce_site_id', $site->id) : $query->whereRaw('1 = 0');
        $this->applyFilters($query, $request);
        $questions = $query->orderByDesc('external_id')->paginate(50)->withQueryString();

        return $content
            ->title('售前问答')
            ->description('本地同步、分析与远程回复')
            ->body(view('admin.product-questions.index', [
                'sites' => $sites,
                'site' => $site,
                'questions' => $questions,
                'filters' => $request->query(),
                'counts' => $site ? $this->counts($site) : [],
            ]));
    }

    public function show(
        Request $request,
        int $questionId,
        Content $content,
    ): Content {
        $site = CommerceSite::query()->where('enabled', true)->findOrFail($request->integer('site_id'));
        $question = ProductQuestion::query()->with('analysis')
            ->where('commerce_site_id', $site->id)
            ->where('external_id', $questionId)
            ->firstOrFail();
        $question->draftRequests()
            ->whereIn('status', ['queued', 'running'])
            ->where('updated_at', '<', now()->subMinutes(10))
            ->each(fn (ProductQuestionDraftRequest $draftRequest) => $draftRequest->update([
                'status' => 'failed',
                'error_message' => 'n8n Draft 工作流超过 10 分钟未完成，请检查 Execution 后重试。',
                'finished_at' => now(),
            ]));
        $draft = $question->drafts()->latest('id')->first();
        $draftRequest = $question->draftRequests()->latest('id')->first();
        $preview = session($this->previewKey($site, $questionId));
        $productSnapshot = CommerceProduct::query()
            ->where('commerce_site_id', $site->id)
            ->where('sn', $question->sn)
            ->first();

        return $content
            ->title('问答 #'.$questionId)
            ->description($site->name)
            ->body(view('admin.product-questions.show', compact('site', 'question', 'preview', 'productSnapshot', 'draft', 'draftRequest')));
    }

    public function generateDraft(
        Request $request,
        int $questionId,
        N8nDraftWorkflowClient $n8n,
        ProductQuestionDraftInput $draftInput,
        WorkflowRegistry $workflows,
        ProductQuestionWorkItemManager $workItems,
    ): RedirectResponse {
        $validated = $request->validate([
            'site_id' => ['required', 'integer'],
            'force' => ['sometimes', 'boolean'],
        ]);
        $site = CommerceSite::query()->where('enabled', true)->findOrFail($request->integer('site_id'));
        $question = ProductQuestion::query()
            ->where('commerce_site_id', $site->id)
            ->where('external_id', $questionId)
            ->firstOrFail();
        if (! $question->remote_present || $question->reply_status !== 'unanswered') {
            admin_toastr('只有仍在线且尚未回复的问题可以生成 Draft。', 'warning');

            return back();
        }
        try {
            $workflows->active(N8nProductQuestionDraftService::WORKFLOW_KEY, N8nProductQuestionDraftService::WORKFLOW_VERSION);
        } catch (Throwable $exception) {
            admin_toastr($this->message($exception), 'error');

            return back();
        }
        $input = $draftInput->capture($question);
        $force = (bool) ($validated['force'] ?? false);
        $reusable = $question->draftRequests()
            ->where('status', 'completed')
            ->where('workflow_key', N8nProductQuestionDraftService::WORKFLOW_KEY)
            ->where('workflow_version', N8nProductQuestionDraftService::WORKFLOW_VERSION)
            ->where('input_hash', $input['hash'])
            ->whereNotNull('product_question_draft_id')
            ->latest('id')
            ->first();
        if (! $force && $reusable !== null) {
            $reusedRequest = ProductQuestionDraftRequest::create([
                'request_id' => (string) Str::uuid(),
                'product_question_id' => $question->id,
                'product_question_draft_id' => $reusable->product_question_draft_id,
                'workflow_key' => N8nProductQuestionDraftService::WORKFLOW_KEY,
                'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
                'input_hash' => $input['hash'],
                'input_snapshot' => $input['snapshot'],
                'reused_from_request_id' => $reusable->id,
                'status' => 'completed',
                'finished_at' => now(),
            ]);
            $workItems->attach($reusedRequest);
            admin_toastr('问题、商品、Policy 和工作流输入均未变化，已复用现有 Draft。', 'info');

            return redirect(admin_url("product-questions/{$questionId}?site_id={$site->id}"));
        }
        $active = $question->draftRequests()
            ->whereIn('status', ['queued', 'running'])
            ->where('created_at', '>=', now()->subMinutes(15))
            ->latest('id')
            ->first();
        if ($active !== null) {
            admin_toastr('该问题已有正在运行的 n8n Draft 请求，请等待完成。', 'warning');

            return back();
        }
        $draftRequest = ProductQuestionDraftRequest::create([
            'request_id' => (string) Str::uuid(),
            'product_question_id' => $question->id,
            'workflow_key' => N8nProductQuestionDraftService::WORKFLOW_KEY,
            'workflow_version' => N8nProductQuestionDraftService::WORKFLOW_VERSION,
            'input_hash' => $input['hash'],
            'input_snapshot' => $input['snapshot'],
            'force_regeneration' => $force,
            'status' => 'queued',
        ]);
        $workItems->attach($draftRequest);
        try {
            $n8n->trigger($draftRequest);
            admin_toastr('n8n Draft 工作流已进入队列；页面会自动刷新状态。', 'success');
        } catch (Throwable $exception) {
            $draftRequest->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                'finished_at' => now(),
            ]);
            admin_toastr('无法启动 n8n Draft 工作流：'.$this->message($exception), 'error');
        }

        return redirect(admin_url("product-questions/{$questionId}?site_id={$site->id}"));
    }

    public function previewReply(
        Request $request,
        int $questionId,
        ProductQuestionClient $client,
        ProductQuestionDraftFeedbackRecorder $feedback,
    ): RedirectResponse {
        $site = CommerceSite::query()->where('enabled', true)->findOrFail($request->integer('site_id'));
        $question = ProductQuestion::query()->with('analysis')
            ->where('commerce_site_id', $site->id)
            ->where('external_id', $questionId)
            ->firstOrFail();
        $payload = $request->validate([
            'answer' => ['required', 'string', 'max:100000'],
            'visible' => ['required', Rule::in(['0', '1', 0, 1])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $payload['visible'] = (int) $payload['visible'];
        $payload['reason'] = trim((string) ($payload['reason'] ?? ''))
            ?: "CSI reviewed product question reply #{$questionId}";
        $latestDraft = $question->drafts()->latest('id')->first();
        if ($payload['visible'] === 1
            && ($question->analysis?->intent_key === 'ask_order_status'
                || ($latestDraft !== null && ! $latestDraft->recommended_visible))
        ) {
            return back()->withErrors(['visible' => '该问题涉及私密订单事实或被安全规则标记，必须使用隐藏回复。'])->withInput();
        }

        try {
            $result = $client->previewReply($site, $questionId, $payload);
        } catch (Throwable $exception) {
            admin_toastr($this->message($exception), 'error');

            return back()->withInput();
        }

        session()->put($this->previewKey($site, $questionId), [
            'payload' => $payload,
            'confirm_token' => data_get($result, 'operation.confirm_token'),
            'confirm_expires_at' => data_get($result, 'operation.confirm_expires_at'),
            'changes' => data_get($result, 'operation.changes', []),
            'notification' => data_get($result, 'notification', []),
            'idempotency_key' => (string) Str::uuid(),
            'draft_feedback_id' => $feedback->recordPreview(
                $question,
                $latestDraft,
                $payload['answer'],
                $payload['visible'] === 1,
                $payload['reason'],
                Admin::user()->id,
            )->id,
        ]);
        admin_toastr('Preview 已生成；请核对差异后确认。', 'success');

        return redirect(admin_url("product-questions/{$questionId}?site_id={$site->id}"));
    }

    public function confirmReply(
        Request $request,
        int $questionId,
        ProductQuestionClient $client,
        ProductQuestionSyncService $sync,
    ): RedirectResponse {
        $site = CommerceSite::query()->where('enabled', true)->findOrFail($request->integer('site_id'));
        $key = $this->previewKey($site, $questionId);
        $preview = session($key);
        abort_unless(
            is_array($preview)
                && is_array($preview['payload'] ?? null)
                && is_string($preview['confirm_token'] ?? null)
                && is_string($preview['idempotency_key'] ?? null),
            422,
            '请先执行 Preview。',
        );

        try {
            $result = $client->confirmReply(
                $site,
                $questionId,
                $preview['payload'],
                $preview['confirm_token'],
                $preview['idempotency_key'],
            );
        } catch (Throwable $exception) {
            admin_toastr($this->message($exception).'；如需重试，将继续复用原幂等键。', 'error');

            return back();
        }

        if (is_array($result['question'] ?? null)) {
            $sync->storeQuestion($site, $result['question']);
        }
        if (is_numeric($preview['draft_feedback_id'] ?? null)) {
            ProductQuestionDraftFeedback::query()
                ->whereKey((int) $preview['draft_feedback_id'])
                ->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        }
        session()->forget($key);
        $notification = data_get($result, 'notification.status');
        admin_toastr('回复已提交'.($notification ? "，通知状态：{$notification}" : '').'。', 'success');

        return redirect(admin_url("product-questions/{$questionId}?site_id={$site->id}"));
    }

    public function review(Request $request, int $questionId): RedirectResponse
    {
        $site = CommerceSite::query()->where('enabled', true)->findOrFail($request->integer('site_id'));
        $question = ProductQuestion::query()
            ->where('commerce_site_id', $site->id)
            ->where('external_id', $questionId)
            ->firstOrFail();
        $data = $request->validate([
            'review_status' => ['required', Rule::in(['unreviewed', 'reviewing', 'reviewed', 'ignored'])],
            'analysis_notes' => ['nullable', 'string', 'max:10000'],
            'tags' => ['nullable', 'string', 'max:2000'],
        ]);
        $tags = collect(preg_split('/[,，\n]+/u', (string) ($data['tags'] ?? '')))
            ->map(fn (string $tag) => trim($tag))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $question->update([
            'review_status' => $data['review_status'],
            'analysis_notes' => trim((string) ($data['analysis_notes'] ?? '')) ?: null,
            'tags' => $tags,
            'reviewed_by' => Admin::user()->id,
            'reviewed_at' => now(),
        ]);
        admin_toastr('问答分析记录已保存。', 'success');

        return back();
    }

    public function reviewAnalysis(Request $request, int $questionId): RedirectResponse
    {
        $site = CommerceSite::query()->where('enabled', true)->findOrFail($request->integer('site_id'));
        $question = ProductQuestion::query()
            ->where('commerce_site_id', $site->id)
            ->where('external_id', $questionId)
            ->firstOrFail();
        $analysis = $question->analysis()->firstOrFail();
        $validator = Validator::make($request->all(), [
            'primary_category' => ['required', Rule::in(array_keys(ProductQuestionAnalysis::CATEGORIES))],
            'secondary_categories' => ['nullable', 'array'],
            'secondary_categories.*' => [Rule::in(array_keys(ProductQuestionAnalysis::CATEGORIES))],
            'customer_intent' => ['required', 'string', 'max:2000'],
            'question_scope' => ['required', Rule::in(array_keys(ProductQuestionAnalysis::SCOPES))],
            'answerability' => ['required', Rule::in(array_keys(ProductQuestionAnalysis::ANSWERABILITY))],
            'risk_level' => ['required', Rule::in(array_keys(ProductQuestionAnalysis::RISKS))],
            'required_facts' => ['nullable', 'string', 'max:10000'],
            'missing_facts' => ['nullable', 'string', 'max:10000'],
            'response_moves' => ['nullable', 'array'],
            'response_moves.*' => [Rule::in(array_keys(ProductQuestionAnalysis::RESPONSE_MOVES))],
            'reply_pattern' => ['required', Rule::in(array_keys(ProductQuestionAnalysis::REPLY_PATTERNS))],
            'answer_summary' => ['nullable', 'string', 'max:10000'],
            'analysis_summary' => ['required', 'string', 'max:10000'],
            'assessment_relevance' => ['required', Rule::in(['good', 'partial', 'poor', 'not_applicable'])],
            'assessment_completeness' => ['required', Rule::in(['good', 'partial', 'poor', 'not_applicable'])],
            'assessment_clarity' => ['required', Rule::in(['good', 'partial', 'poor', 'not_applicable'])],
            'assessment_consistency' => ['required', Rule::in(['good', 'partial', 'poor', 'not_applicable'])],
            'assessment_policy_safety' => ['required', Rule::in(['good', 'partial', 'poor', 'not_applicable'])],
            'assessment_strengths' => ['nullable', 'string', 'max:10000'],
            'assessment_gaps' => ['nullable', 'string', 'max:10000'],
            'assessment_risky_claims' => ['nullable', 'string', 'max:10000'],
            'automation_recommendation' => ['required', Rule::in(array_keys(ProductQuestionAnalysis::AUTOMATION))],
            'review_status' => ['required', Rule::in(['confirmed', 'corrected', 'rejected'])],
            'review_notes' => ['nullable', 'string', 'max:10000'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            if ($request->input('automation_recommendation') !== 'auto_candidate') {
                return;
            }
            if (
                $request->input('risk_level') !== 'low'
                || $request->input('answerability') !== 'direct'
                || $this->lines($request->input('missing_facts')) !== []
                || $request->input('assessment_policy_safety') === 'poor'
                || $this->lines($request->input('assessment_risky_claims')) !== []
            ) {
                $validator->errors()->add('automation_recommendation', '自动化候选仅允许低风险、可直接回答、事实完整且无风险表述的记录。');
            }
        });
        $data = $validator->validate();
        $secondary = array_values(array_diff(array_unique($data['secondary_categories'] ?? []), [$data['primary_category']]));
        $assessment = [
            'relevance' => $data['assessment_relevance'],
            'completeness' => $data['assessment_completeness'],
            'clarity' => $data['assessment_clarity'],
            'consistency' => $data['assessment_consistency'],
            'policy_safety' => $data['assessment_policy_safety'],
            'strengths' => $this->lines($data['assessment_strengths'] ?? null),
            'gaps' => $this->lines($data['assessment_gaps'] ?? null),
            'prohibited_or_risky_claims' => $this->lines($data['assessment_risky_claims'] ?? null),
        ];
        $analysis->update([
            'primary_category' => $data['primary_category'],
            'secondary_categories' => $secondary,
            'customer_intent' => trim($data['customer_intent']),
            'question_scope' => $data['question_scope'],
            'answerability' => $data['answerability'],
            'risk_level' => $data['risk_level'],
            'required_facts' => $this->lines($data['required_facts'] ?? null),
            'missing_facts' => $this->lines($data['missing_facts'] ?? null),
            'response_moves' => array_values(array_unique($data['response_moves'] ?? [])),
            'reply_pattern' => $data['reply_pattern'],
            'answer_summary' => trim((string) ($data['answer_summary'] ?? '')) ?: null,
            'answer_assessment' => $assessment,
            'automation_recommendation' => $data['automation_recommendation'],
            'analysis_summary' => trim($data['analysis_summary']),
            'review_status' => $data['review_status'],
            'review_notes' => trim((string) ($data['review_notes'] ?? '')) ?: null,
            'reviewed_by' => Admin::user()->id,
            'reviewed_at' => now(),
        ]);
        admin_toastr('AI 分析审核结果已保存。', 'success');

        return back();
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        foreach (['reply_status', 'review_status', 'reply_source'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }
        if ($request->filled('analysis_review_status') && $request->query('analysis_review_status') !== 'missing') {
            $query->whereHas('analysis', fn (Builder $analysis) => $analysis->where('review_status', $request->query('analysis_review_status')));
        }
        foreach (['primary_category', 'risk_level', 'automation_recommendation', 'answer_source', 'work_queue'] as $field) {
            if ($request->filled($field)) {
                $query->whereHas('analysis', fn (Builder $analysis) => $analysis->where($field, $request->query($field)));
            }
        }
        if ($request->filled('intent_key')) {
            $query->whereHas('analysis', fn (Builder $analysis) => $analysis->where('intent_key', trim((string) $request->query('intent_key'))));
        }
        if ($request->query('analysis_review_status') === 'missing') {
            $query->whereDoesntHave('analysis');
        }
        foreach (['visible', 'type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->integer($field));
            }
        }
        if ($request->filled('status')) {
            $query->where('status', $request->integer('status'));
        }
        if ($request->filled('sn')) {
            $query->where('sn', trim((string) $request->query('sn')));
        }
        if ($request->filled('email')) {
            $query->where('email', 'like', '%'.trim((string) $request->query('email')).'%');
        }
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->query('keyword'));
            $query->where(function (Builder $query) use ($keyword): void {
                $query->where('question', 'like', "%{$keyword}%")
                    ->orWhere('answer', 'like', "%{$keyword}%")
                    ->orWhere('sn', 'like', "%{$keyword}%")
                    ->orWhere('product->name', 'like', "%{$keyword}%")
                    ->orWhere('name', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%");
            });
        }
    }

    /** @return array<string, int> */
    private function counts(CommerceSite $site): array
    {
        $query = ProductQuestion::query()->withoutReplay()
            ->where('commerce_site_id', $site->id)
            ->where('remote_present', true);

        return [
            'all' => (clone $query)->count(),
            'unanswered' => (clone $query)->where('reply_status', 'unanswered')->count(),
            'unreviewed' => (clone $query)->where('review_status', 'unreviewed')->count(),
            'reviewed' => (clone $query)->where('review_status', 'reviewed')->count(),
            'ai_analyzed' => (clone $query)->whereHas('analysis')->count(),
            'ai_pending' => (clone $query)->whereHas('analysis', fn (Builder $analysis) => $analysis->where('review_status', 'candidate'))->count(),
        ];
    }

    private function selectedSite(Request $request, $sites): ?CommerceSite
    {
        if ($sites->isEmpty()) {
            return null;
        }

        return $request->filled('site_id')
            ? ($sites->firstWhere('id', $request->integer('site_id')) ?? $sites->first())
            : $sites->first();
    }

    private function previewKey(CommerceSite $site, int $questionId): string
    {
        return "product_question_preview.{$site->id}.{$questionId}";
    }

    private function message(Throwable $exception): string
    {
        if ($exception instanceof TradeManagerApiException) {
            $suffix = $exception->requestId ? "（request_id: {$exception->requestId}）" : '';

            return "{$exception->errorCode}: {$exception->getMessage()}{$suffix}";
        }

        report($exception);

        return '无法连接站点 Admin API，请检查站点地址、Token 和网络。';
    }

    /** @return list<string> */
    private function lines(mixed $value): array
    {
        return collect(preg_split('/[\r\n]+/u', (string) $value))
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
