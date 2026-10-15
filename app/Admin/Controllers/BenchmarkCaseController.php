<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BenchmarkAnnotation;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseStep;
use App\Models\EmailTemplate;
use App\Models\KnowledgeArticle;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BenchmarkCaseController extends Controller
{
    public function index(Request $request, Content $content): Content
    {
        $query = BenchmarkCase::query()
            ->with(['conversation:id,external_id,external_short_id,subject,status,silo,last_message_at'])
            ->withCount(['steps', 'annotations']);

        if ($request->filled('dataset_version')) {
            $query->where('dataset_version', $request->query('dataset_version'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return $content
            ->title('基准集标注')
            ->description('先切分决策阶段，再盲标知识、动作与回复')
            ->body(view('admin.benchmark.index', [
                'cases' => $query->orderBy('id')->paginate(50)->withQueryString(),
                'filters' => $request->query(),
                'datasets' => BenchmarkCase::query()->distinct()->orderBy('dataset_version')->pluck('dataset_version'),
                'counts' => BenchmarkCase::query()
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
            ]));
    }

    public function show(int $id, Content $content): Content
    {
        $case = BenchmarkCase::query()->with([
            'conversation.events' => fn ($query) => $query
                ->with('attachments')
                ->orderBy('occurred_at')
                ->orderBy('id'),
            'steps' => fn ($query) => $query->withCount('annotations')->orderBy('sequence'),
        ])->findOrFail($id);

        return $content
            ->title('基准案例 #'.$case->id)
            ->description($case->dataset_version.' / '.$case->split.' / 阶段切分')
            ->body(view('admin.benchmark.show', [
                'case' => $case,
                'ticket' => $case->conversation,
                'nextCase' => BenchmarkCase::query()
                    ->where('dataset_version', $case->dataset_version)
                    ->where('id', '>', $case->id)
                    ->orderBy('id')
                    ->first(['id']),
            ]));
    }

    public function storeStep(Request $request, int $id): RedirectResponse
    {
        $case = BenchmarkCase::query()->with('conversation.events')->findOrFail($id);
        $data = $this->validatedStepData($request, $case);
        $data['sequence'] = ((int) $case->steps()->max('sequence')) + 1;
        $data['created_by'] = (int) Admin::user()->id;

        $case->steps()->create($data);
        admin_toastr('决策阶段已建立，可以开始盲标。', 'success');

        return back();
    }

    public function updateStep(Request $request, int $id, int $stepId): RedirectResponse
    {
        $case = BenchmarkCase::query()->with('conversation.events')->findOrFail($id);
        $step = $case->steps()->withCount('annotations')->findOrFail($stepId);
        if ($step->annotations_count > 0) {
            throw ValidationException::withMessages([
                'step' => '该阶段已经有人工标注。为防止上下文与答案错位，不能再修改边界。',
            ]);
        }

        $step->update($this->validatedStepData($request, $case));
        admin_toastr('阶段边界已更新。', 'success');

        return back();
    }

    public function showStep(int $id, int $stepId, Content $content): Content
    {
        $userId = (int) Admin::user()->id;
        $case = BenchmarkCase::query()->with([
            'conversation.events' => fn ($query) => $query
                ->with('attachments')
                ->orderBy('occurred_at')
                ->orderBy('id'),
            'steps' => fn ($query) => $query->orderBy('sequence'),
        ])->findOrFail($id);
        $step = $case->steps->firstWhere('id', $stepId);
        abort_if($step === null, 404);

        $annotation = $step->annotations()->where('annotator_id', $userId)->first();
        $observedReplyEvents = $annotation === null
            ? collect()
            : $case->conversation->events->whereIn('id', $step->observed_reply_event_ids)->values();

        return $content
            ->title('基准案例 #'.$case->id.' · 阶段 '.$step->sequence)
            ->description($step->title)
            ->body(view('admin.benchmark.step', [
                'case' => $case,
                'step' => $step,
                'ticket' => $case->conversation,
                'events' => $this->visibleEvents($case, $step),
                'observedReplyEvents' => $observedReplyEvents,
                'annotation' => $annotation,
                'nextStep' => $case->steps->where('sequence', '>', $step->sequence)->first(),
                'intents' => BenchmarkAnnotation::INTENTS,
                'requiredFacts' => BenchmarkAnnotation::REQUIRED_FACTS,
                'decisions' => BenchmarkAnnotation::DECISIONS,
                'automationLevels' => BenchmarkAnnotation::AUTOMATION_LEVELS,
                'lifecycleStages' => BenchmarkAnnotation::LIFECYCLE_STAGES,
                'responseStrategies' => BenchmarkAnnotation::RESPONSE_STRATEGIES,
                'requiredActions' => BenchmarkAnnotation::REQUIRED_ACTIONS,
                'replyAssessments' => BenchmarkAnnotation::OBSERVED_REPLY_ASSESSMENTS,
                'knowledgeArticles' => KnowledgeArticle::query()
                    ->where('status', '!=', 'deprecated')
                    ->orderByRaw("case status when 'verified' then 0 when 'observed' then 1 else 2 end")
                    ->orderBy('title')
                    ->get(),
                'knowledgeStatuses' => KnowledgeArticle::STATUSES,
                'knowledgeTypes' => KnowledgeArticle::TYPES,
                'templates' => EmailTemplate::query()
                    ->where('active', true)
                    ->where('template_type', 'template')
                    ->orderBy('name')
                    ->get(['id', 'name', 'subject']),
            ]));
    }

    public function storeStepAnnotation(Request $request, int $id, int $stepId): RedirectResponse
    {
        $case = BenchmarkCase::query()->with('conversation.events')->findOrFail($id);
        $step = $case->steps()->findOrFail($stepId);
        $data = $this->validatedStepAnnotationData($request, $case, $step);
        $userId = (int) Admin::user()->id;
        $markAsGold = (bool) $data['mark_as_gold'];
        $newKnowledge = $data['new_knowledge'];
        unset($data['mark_as_gold'], $data['new_knowledge']);

        DB::transaction(function () use ($case, $step, $data, $userId, $markAsGold, $newKnowledge): void {
            if ($newKnowledge !== null) {
                $article = KnowledgeArticle::create($newKnowledge);
                $data['knowledge_article_ids'][] = $article->id;
                $data['knowledge_article_ids'] = array_values(array_unique($data['knowledge_article_ids']));
            }

            BenchmarkAnnotation::updateOrCreate(
                [
                    'benchmark_case_id' => $case->id,
                    'benchmark_case_step_id' => $step->id,
                    'annotator_id' => $userId,
                    'annotation_round' => 1,
                ],
                $data,
            );

            $step->update(['status' => $markAsGold ? 'adjudicated' : ($step->status === 'adjudicated' ? 'adjudicated' : 'annotated')]);
            if ($markAsGold) {
                $step->update($this->goldData($data, $userId));
            }

            $this->refreshCaseStatus($case);
        });

        admin_toastr($markAsGold ? '阶段标注已保存并确认为金标准。' : '阶段个人标注已保存。', 'success');

        return back();
    }

    /** Compatibility endpoint for the original Ticket-level pilot annotation. */
    public function storeAnnotation(Request $request, int $id): RedirectResponse
    {
        $case = BenchmarkCase::query()->with('conversation.events')->findOrFail($id);
        $eventIds = $case->conversation->events->pluck('id')->all();
        $data = $request->validate([
            'site_key' => ['required', Rule::in(['dressafford.com', 'ellenbridals.com', 'unknown'])],
            'intents' => ['required', 'array', 'min:1'],
            'intents.*' => [Rule::in(array_keys(BenchmarkAnnotation::INTENTS))],
            'primary_intent' => ['required', Rule::in(array_keys(BenchmarkAnnotation::INTENTS))],
            'risk_level' => ['required', Rule::in(['normal', 'high', 'critical'])],
            'required_facts' => ['nullable', 'array'],
            'required_facts.*' => [Rule::in(array_keys(BenchmarkAnnotation::REQUIRED_FACTS))],
            'missing_information' => ['nullable', 'string', 'max:5000'],
            'decision' => ['required', Rule::in(array_keys(BenchmarkAnnotation::DECISIONS))],
            'email_template_id' => [
                'nullable',
                Rule::exists('email_templates', 'id')->where(fn ($query) => $query
                    ->where('active', true)
                    ->where('template_type', 'template')),
            ],
            'ideal_reply' => ['nullable', 'string', 'max:100000'],
            'automation_level' => ['required', Rule::in(array_keys(BenchmarkAnnotation::AUTOMATION_LEVELS))],
            'evidence_event_ids' => ['required', 'array', 'min:1'],
            'evidence_event_ids.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'mark_as_gold' => ['nullable', 'boolean'],
        ]);

        if (! in_array($data['primary_intent'], $data['intents'], true)) {
            throw ValidationException::withMessages(['primary_intent' => '主要意图必须包含在已选客户意图中。']);
        }
        if (array_diff(array_map('intval', $data['evidence_event_ids']), $eventIds) !== []) {
            throw ValidationException::withMessages(['evidence_event_ids' => '证据消息必须属于当前 Ticket。']);
        }

        $data['intents'] = array_values(array_unique($data['intents']));
        $data['required_facts'] = array_values(array_unique($data['required_facts'] ?? []));
        $data['missing_information'] = $this->lines($data['missing_information'] ?? null);
        $data['evidence_event_ids'] = array_values(array_unique(array_map('intval', $data['evidence_event_ids'])));
        $data['ideal_reply'] = trim((string) ($data['ideal_reply'] ?? '')) ?: null;
        $data['notes'] = trim((string) ($data['notes'] ?? '')) ?: null;
        $data['submitted_at'] = now();
        $markAsGold = (bool) ($data['mark_as_gold'] ?? false);
        unset($data['mark_as_gold']);
        $userId = (int) Admin::user()->id;

        DB::transaction(function () use ($case, $data, $userId, $markAsGold): void {
            BenchmarkAnnotation::updateOrCreate(
                [
                    'benchmark_case_id' => $case->id,
                    'benchmark_case_step_id' => null,
                    'annotator_id' => $userId,
                    'annotation_round' => 1,
                ],
                $data,
            );
            $case->update(['status' => $markAsGold ? 'adjudicated' : ($case->status === 'adjudicated' ? 'adjudicated' : 'annotated')]);
            if ($markAsGold) {
                $case->update([
                    'gold_site_key' => $data['site_key'],
                    'gold_intents' => $data['intents'],
                    'gold_primary_intent' => $data['primary_intent'],
                    'gold_risk_level' => $data['risk_level'],
                    'gold_required_facts' => $data['required_facts'],
                    'gold_missing_information' => $data['missing_information'],
                    'gold_decision' => $data['decision'],
                    'gold_email_template_id' => $data['email_template_id'] ?? null,
                    'gold_reply' => $data['ideal_reply'],
                    'gold_automation_level' => $data['automation_level'],
                    'gold_evidence_event_ids' => $data['evidence_event_ids'],
                    'gold_notes' => $data['notes'],
                    'adjudicated_by' => $userId,
                    'adjudicated_at' => now(),
                ]);
            }
        });

        return back();
    }

    private function validatedStepData(Request $request, BenchmarkCase $case): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'trigger_event_id' => ['required', 'integer'],
            'context_start_event_id' => ['nullable', 'integer'],
            'observed_reply_event_ids' => ['nullable', 'array'],
            'observed_reply_event_ids.*' => ['integer'],
            'requires_response' => ['required', 'boolean'],
            'stage_end_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $events = $case->conversation->events
            ->sortBy(fn ($event) => sprintf('%s-%020d', $event->occurred_at?->format('c') ?? '', $event->id))
            ->values();
        $positions = $events->pluck('id')->flip();
        $trigger = $events->firstWhere('id', (int) $data['trigger_event_id']);
        if ($trigger === null || ! in_array($trigger->author_type, ['client', 'customer'], true)) {
            throw ValidationException::withMessages(['trigger_event_id' => '阶段触发点必须是当前 Ticket 的客户消息。']);
        }

        $startId = (int) ($data['context_start_event_id'] ?? $events->first()->id);
        if (! $positions->has($startId) || $positions[$startId] > $positions[$trigger->id]) {
            throw ValidationException::withMessages(['context_start_event_id' => '上下文起点必须属于当前 Ticket，且不能晚于触发消息。']);
        }

        $replyIds = array_values(array_unique(array_map('intval', $data['observed_reply_event_ids'] ?? [])));
        foreach ($replyIds as $replyId) {
            $reply = $events->firstWhere('id', $replyId);
            if ($reply === null || $reply->author_type !== 'agent' || $positions[$replyId] <= $positions[$trigger->id]) {
                throw ValidationException::withMessages(['observed_reply_event_ids' => '历史回复必须是触发消息之后、属于当前 Ticket 的客服消息。']);
            }
        }

        return [
            'title' => trim($data['title']),
            'trigger_event_id' => (int) $trigger->id,
            'context_start_event_id' => $startId,
            'context_end_event_id' => (int) $trigger->id,
            'observed_reply_event_ids' => $replyIds,
            'requires_response' => (bool) $data['requires_response'],
            'stage_end_reason' => trim((string) ($data['stage_end_reason'] ?? '')) ?: null,
        ];
    }

    private function validatedStepAnnotationData(Request $request, BenchmarkCase $case, BenchmarkCaseStep $step): array
    {
        $data = $request->validate([
            'site_key' => ['required', Rule::in(['dressafford.com', 'ellenbridals.com', 'unknown'])],
            'lifecycle_stage' => ['required', Rule::in(array_keys(BenchmarkAnnotation::LIFECYCLE_STAGES))],
            'customer_goal' => ['required', 'string', 'max:2000'],
            'intents' => ['required', 'array', 'min:1'],
            'intents.*' => [Rule::in(array_keys(BenchmarkAnnotation::INTENTS))],
            'primary_intent' => ['required', Rule::in(array_keys(BenchmarkAnnotation::INTENTS))],
            'risk_level' => ['required', Rule::in(['normal', 'high', 'critical'])],
            'required_facts' => ['nullable', 'array'],
            'required_facts.*' => [Rule::in(array_keys(BenchmarkAnnotation::REQUIRED_FACTS))],
            'missing_information' => ['nullable', 'string', 'max:5000'],
            'decision' => ['required', Rule::in(array_keys(BenchmarkAnnotation::DECISIONS))],
            'response_strategy' => ['required', Rule::in(array_keys(BenchmarkAnnotation::RESPONSE_STRATEGIES))],
            'required_actions' => ['nullable', 'array'],
            'required_actions.*' => [Rule::in(array_keys(BenchmarkAnnotation::REQUIRED_ACTIONS))],
            'knowledge_article_ids' => ['nullable', 'array'],
            'knowledge_article_ids.*' => ['integer', Rule::exists('knowledge_articles', 'id')],
            'knowledge_gaps' => ['nullable', 'string', 'max:10000'],
            'prohibited_claims' => ['nullable', 'string', 'max:10000'],
            'new_knowledge_key' => ['nullable', 'required_with:new_knowledge_title', 'alpha_dash:ascii', 'max:120', Rule::unique('knowledge_articles', 'key')],
            'new_knowledge_title' => ['nullable', 'string', 'max:255', 'required_with:new_knowledge_key'],
            'new_knowledge_type' => ['nullable', Rule::in(array_keys(KnowledgeArticle::TYPES))],
            'new_knowledge_applicability' => ['nullable', 'string', 'max:5000'],
            'new_knowledge_source_notes' => ['nullable', 'string', 'max:10000'],
            'email_template_id' => [
                'nullable',
                Rule::exists('email_templates', 'id')->where(fn ($query) => $query
                    ->where('active', true)
                    ->where('template_type', 'template')),
            ],
            'ideal_reply' => ['nullable', 'string', 'max:100000'],
            'automation_level' => ['required', Rule::in(array_keys(BenchmarkAnnotation::AUTOMATION_LEVELS))],
            'evidence_event_ids' => ['required', 'array', 'min:1'],
            'evidence_event_ids.*' => ['integer'],
            'observed_reply_assessment' => ['nullable', Rule::in(array_keys(BenchmarkAnnotation::OBSERVED_REPLY_ASSESSMENTS))],
            'notes' => ['nullable', 'string', 'max:10000'],
            'mark_as_gold' => ['nullable', 'boolean'],
        ]);

        if (! in_array($data['primary_intent'], $data['intents'], true)) {
            throw ValidationException::withMessages(['primary_intent' => '主要意图必须包含在已选客户意图中。']);
        }

        $visibleEventIds = $this->visibleEvents($case, $step)->pluck('id')->all();
        $evidenceIds = array_values(array_unique(array_map('intval', $data['evidence_event_ids'])));
        if (array_diff($evidenceIds, $visibleEventIds) !== []) {
            throw ValidationException::withMessages([
                'evidence_event_ids' => '证据只能选择当前阶段截止点之前的可见消息。可见事件：'.implode(', ', $visibleEventIds),
            ]);
        }

        $knowledgeIds = array_values(array_unique(array_map('intval', $data['knowledge_article_ids'] ?? [])));
        $knowledgeGaps = $this->lines($data['knowledge_gaps'] ?? null);
        $missingInformation = $this->lines($data['missing_information'] ?? null);
        $requiredActions = array_values(array_unique($data['required_actions'] ?? []));
        if ($data['automation_level'] === 'auto_send') {
            $nonVerifiedCount = KnowledgeArticle::query()->whereIn('id', $knowledgeIds)->where('status', '!=', 'verified')->count()
                + (($data['new_knowledge_key'] ?? null) !== null ? 1 : 0);
            $writeActions = array_intersect($requiredActions, ['update_order', 'update_measurements', 'request_approval']);
            if ($data['risk_level'] !== 'normal' || $missingInformation !== [] || $knowledgeGaps !== [] || $nonVerifiedCount > 0 || $writeActions !== []) {
                throw ValidationException::withMessages([
                    'automation_level' => '存在风险、缺失信息、未验证知识或业务写操作时，不能标记为可自动发送。',
                ]);
            }
        }

        if ($step->requires_response && $data['automation_level'] !== 'noise' && trim((string) ($data['ideal_reply'] ?? '')) === '') {
            throw ValidationException::withMessages(['ideal_reply' => '需要回复的有效阶段必须填写理想回复。']);
        }

        $newKnowledge = null;
        if (($data['new_knowledge_key'] ?? null) !== null) {
            $newKnowledge = [
                'key' => $data['new_knowledge_key'],
                'title' => trim($data['new_knowledge_title']),
                'knowledge_type' => $data['new_knowledge_type'] ?? 'process',
                'status' => 'observed',
                'site_key' => $data['site_key'] === 'unknown' ? null : $data['site_key'],
                'applicability' => trim((string) ($data['new_knowledge_applicability'] ?? '')) ?: null,
                'source_notes' => trim((string) ($data['new_knowledge_source_notes'] ?? '')) ?: '由基准案例 #'.$case->id.' 阶段 '.$step->sequence.' 提出，待业务确认。',
            ];
        }

        return [
            'site_key' => $data['site_key'],
            'lifecycle_stage' => $data['lifecycle_stage'],
            'customer_goal' => trim($data['customer_goal']),
            'intents' => array_values(array_unique($data['intents'])),
            'primary_intent' => $data['primary_intent'],
            'risk_level' => $data['risk_level'],
            'required_facts' => array_values(array_unique($data['required_facts'] ?? [])),
            'missing_information' => $missingInformation,
            'decision' => $data['decision'],
            'response_strategy' => $data['response_strategy'],
            'required_actions' => $requiredActions,
            'knowledge_article_ids' => array_values(array_unique($knowledgeIds)),
            'knowledge_gaps' => $knowledgeGaps,
            'prohibited_claims' => $this->lines($data['prohibited_claims'] ?? null),
            'email_template_id' => $data['email_template_id'] ?? null,
            'ideal_reply' => trim((string) ($data['ideal_reply'] ?? '')) ?: null,
            'automation_level' => $data['automation_level'],
            'evidence_event_ids' => $evidenceIds,
            'observed_reply_assessment' => $data['observed_reply_assessment'] ?? null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'submitted_at' => now(),
            'mark_as_gold' => (bool) ($data['mark_as_gold'] ?? false),
            'new_knowledge' => $newKnowledge,
        ];
    }

    /** @return Collection<int, mixed> */
    private function visibleEvents(BenchmarkCase $case, BenchmarkCaseStep $step): Collection
    {
        $events = $case->conversation->events
            ->sortBy(fn ($event) => sprintf('%s-%020d', $event->occurred_at?->format('c') ?? '', $event->id))
            ->values();
        $start = $events->search(fn ($event) => (int) $event->id === (int) $step->context_start_event_id);
        $end = $events->search(fn ($event) => (int) $event->id === (int) $step->context_end_event_id);
        if ($start === false || $end === false || $start > $end) {
            return collect();
        }

        return $events->slice($start, $end - $start + 1)->values();
    }

    private function refreshCaseStatus(BenchmarkCase $case): void
    {
        $steps = $case->steps()->get(['status']);
        $status = $steps->isNotEmpty() && $steps->every(fn (BenchmarkCaseStep $step) => $step->status === 'adjudicated')
            ? 'adjudicated'
            : ($steps->contains(fn (BenchmarkCaseStep $step) => in_array($step->status, ['annotated', 'adjudicated'], true)) ? 'annotated' : 'pending');
        $case->update(['status' => $status]);
    }

    private function goldData(array $data, int $userId): array
    {
        $mapping = [
            'site_key', 'lifecycle_stage', 'customer_goal', 'intents', 'primary_intent', 'risk_level',
            'required_facts', 'missing_information', 'decision', 'response_strategy', 'required_actions',
            'knowledge_article_ids', 'knowledge_gaps', 'prohibited_claims', 'email_template_id', 'automation_level',
            'evidence_event_ids', 'observed_reply_assessment', 'notes',
        ];
        $gold = [];
        foreach ($mapping as $field) {
            $gold['gold_'.$field] = $data[$field] ?? null;
        }
        $gold['gold_reply'] = $data['ideal_reply'];
        $gold['adjudicated_by'] = $userId;
        $gold['adjudicated_at'] = now();

        return $gold;
    }

    /** @return array<int, string> */
    private function lines(?string $value): array
    {
        return collect(preg_split('/\R/u', (string) $value) ?: [])
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
