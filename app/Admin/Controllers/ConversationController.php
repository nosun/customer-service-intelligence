<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ActionProposal;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationDraft;
use App\Models\DecisionCase;
use App\Models\DecisionNode;
use App\Models\WorkItem;
use App\Services\Cancellations\CancellationCaseVerifier;
use App\Services\N8n\N8nTicketDraftWorkflowClient;
use App\Services\N8n\WorkflowRegistry;
use App\Services\TicketWork\N8nTicketDraftService;
use App\Services\TicketWork\TicketAssistanceFeedbackRecorder;
use App\Services\TicketWork\TicketDraftInput;
use App\Services\TicketWork\TicketOrderWorkbench;
use App\Services\TicketWork\TicketWorkItemManager;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class ConversationController extends Controller
{
    public function index(Request $request, Content $content): Content
    {
        $agent = $this->currentAgent();
        $query = Conversation::query()
            ->with(['channelAccount', 'analysis', 'decisionCase', 'runtimeCaseSource.customerCase.workItems' => fn ($query) => $query->whereIn('work_type', [TicketWorkItemManager::WORK_TYPE, 'ticket_agent'])->latest('id')->limit(1)])
            ->withCount('events');

        $this->applyFilters($query, $request, $agent['id'] ?? null);

        $tickets = $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at_external')
            ->paginate(50)
            ->withQueryString();

        return $content
            ->title('Ticket 工作台')
            ->description('本地同步、分类与案例审核')
            ->body(view('admin.tickets.index', [
                'tickets' => $tickets,
                'counts' => $this->counts($agent['id'] ?? null),
                'filters' => $request->query(),
                'currentAgentName' => $agent['name'] ?? null,
                'lastSyncedAt' => ChannelAccount::where('channel', 'helpdesk')->max('last_synced_at'),
                'problemTypes' => DB::table('conversation_analyses')
                    ->whereNotNull('primary_problem_type')
                    ->distinct()
                    ->orderBy('primary_problem_type')
                    ->pluck('primary_problem_type'),
                'sites' => Conversation::query()
                    ->whereNotNull('site_key')
                    ->distinct()
                    ->orderBy('site_key')
                    ->pluck('site_key'),
            ]));
    }

    public function show(int $id, Content $content): Content
    {
        $ticket = Conversation::query()->with([
            'channelAccount',
            'analysis',
            'assistanceFeedback' => fn ($query) => $query->latest('id')->limit(30),
            'labels',
            'events' => fn ($query) => $query->with('attachments')->orderBy('occurred_at')->orderBy('id'),
            'decisionCase.nodes.evidence.event',
            'drafts' => fn ($query) => $query->whereIn('status', ['draft', 'blocked'])->latest('updated_at'),
            'runtimeCaseSource.customerCase.workItems' => fn ($query) => $query
                ->whereIn('work_type', [TicketWorkItemManager::WORK_TYPE, 'ticket_agent'])->latest('id')->limit(20),
            'latestCancellationContext',
            'latestOrderContext',
        ])->findOrFail($id);

        return $content
            ->title($ticket->subject ?: 'Ticket '.$ticket->external_short_id)
            ->description($ticket->external_short_id ?: $ticket->external_id)
            ->body(view('admin.tickets.show', [
                'ticket' => $ticket,
                'responseCurrent' => $ticket->runtimeCaseSource?->customerCase?->workItems?->first() === null
                    || $this->workItemCurrent($ticket, $ticket->runtimeCaseSource->customerCase->workItems->first()),
                'draftCurrent' => $ticket->drafts->first() === null || $this->draftCurrent($ticket, $ticket->drafts->first()),
                'orderContext' => $ticket->latestOrderContext,
                'contextFresh' => $ticket->latestOrderContext !== null && app(TicketOrderWorkbench::class)->isFresh($ticket, $ticket->latestOrderContext),
                'orderActions' => ActionProposal::where('parameters->conversation_id', $ticket->id)->latest('id')->limit(10)->get(),
                'relatedTickets' => filled($ticket->linked_order_number) ? Conversation::where('linked_order_number', $ticket->linked_order_number)->where('id', '!=', $ticket->id)->get(['id', 'external_short_id', 'status']) : collect(),
            ]));
    }

    public function storeDraft(Request $request, int $id): RedirectResponse
    {
        $ticket = Conversation::findOrFail($id);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:100000'],
            'is_private' => ['nullable', 'boolean'],
            'status_after_send' => ['nullable', Rule::in(['open', 'pending', 'onhold', 'solved', 'closed'])],
        ]);
        $userId = (int) Admin::user()->id;

        DB::transaction(function () use ($ticket, $userId, $data): void {
            Conversation::lockForUpdate()->findOrFail($ticket->id);
            $previous = ConversationDraft::where('conversation_id', $ticket->id)->where('created_by', $userId)->where('source', 'manual')->where('status', 'draft')->first();
            app(TicketAssistanceFeedbackRecorder::class)->record($ticket, $userId,
                ['target' => 'draft', 'kind' => 'manual_save', 'body' => ''], snapshot: [
                    'draft_id' => $previous?->id, 'before' => $previous?->body, 'after' => trim((string) ($data['body'] ?? '')),
                ]);
            ConversationDraft::updateOrCreate(
                [
                    'conversation_id' => $ticket->id,
                    'created_by' => $userId,
                    'source' => 'manual',
                    'status' => 'draft',
                ],
                [
                    'metadata' => ['workbench_input_hash' => app(TicketOrderWorkbench::class)->inputHash($ticket), 'order_context_id' => $ticket->latestOrderContext()->value('id')],
                    'body' => trim((string) ($data['body'] ?? '')),
                    'is_private' => (bool) ($data['is_private'] ?? false),
                    'status_after_send' => $data['status_after_send'] ?? null,
                    'updated_by' => $userId,
                ],
            );

        });
        admin_toastr('草稿已保存，仅保存在本地。', 'success');

        return back();
    }

    public function generateDraft(
        Request $request,
        int $id,
        TicketWorkItemManager $workItems,
        N8nTicketDraftWorkflowClient $n8n,
        WorkflowRegistry $workflows,
        TicketOrderWorkbench $workbench,
    ): RedirectResponse {
        $ticket = Conversation::query()->with('analysis')->findOrFail($id);
        if ($ticket->silo !== 'tickets' || ! in_array($ticket->status, ['open', 'pending', 'onhold'], true)) {
            admin_toastr('只有当前工作区中仍需处理的 Ticket 可以创建在线 Draft WorkItem。', 'warning');

            return redirect(admin_url('conversations/'.$id).'#reply-draft');
        }
        $data = $request->validate([
            'draft_guidance' => ['nullable', 'string', 'max:10000'],
            'inspect_order' => ['sometimes', 'boolean'],
        ]);
        try {
            if ($request->exists('draft_guidance')) {
                $text = trim((string) ($data['draft_guidance'] ?? '')) ?: null;
                if ($ticket->draft_guidance !== $text) {
                    app(TicketAssistanceFeedbackRecorder::class)->guidance($ticket, $text, (int) Admin::user()->id);
                    $ticket->refresh();
                }
            }
            $investigation = (bool) config('ticket-assistance.enabled') || (bool) config('services.n8n.ticket_investigation_enabled');
            $workflowKey = $investigation ? N8nTicketDraftService::INVESTIGATION_KEY : N8nTicketDraftService::WORKFLOW_KEY;
            $workflowVersion = $investigation ? N8nTicketDraftService::INVESTIGATION_VERSION : N8nTicketDraftService::WORKFLOW_VERSION;
            $workflows->runnable($workflowKey, $workflowVersion);
            if (! $investigation && $request->boolean('inspect_order')) {
                $snapshot = $ticket->latestOrderContext()->first();
                if ($snapshot === null || ! $workbench->isFresh($ticket, $snapshot)) {
                    $snapshot = $workbench->inspect($ticket, actor: (int) Admin::user()->id);
                }
                if ($snapshot->lookup_status !== 'found') {
                    admin_toastr('订单核查未通过，尚未启动生成：'.$snapshot->error_message, 'warning');

                    return redirect(admin_url('conversations/'.$id).'#order-workbench');
                }
            }
            $item = $workItems->create(
                $ticket,
                (int) Admin::user()->id,
                $workflowKey,
                $workflowVersion,
            );
            if ($item->wasRecentlyCreated) {
                $n8n->trigger($item);
                admin_toastr('已开始生成回复草稿，请稍后刷新查看结果。仅保存在本地，发送仍在 HelpDesk。', 'success');
            } else {
                admin_toastr('相同输入已有正在运行的 Ticket Draft WorkItem。', 'info');
            }
        } catch (Throwable $exception) {
            if (isset($item) && $item->status === 'queued') {
                $item->update([
                    'status' => 'failed',
                    'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                    'finished_at' => now(),
                ]);
            }
            admin_toastr('无法启动 Ticket Draft：'.mb_substr($exception->getMessage(), 0, 300), 'error');
        }

        return redirect(admin_url('conversations/'.$id).'#reply-draft');
    }

    public function reviewDraft(Request $request, int $id, int $draft): RedirectResponse
    {
        $conversationDraft = ConversationDraft::query()
            ->where('conversation_id', $id)
            ->whereIn('source', ['n8n', 'openai_agents'])
            ->findOrFail($draft);
        $data = $request->validate([
            'review_outcome' => ['required', Rule::in(['accepted_as_is', 'edited', 'rejected'])],
            'body' => ['nullable', 'string', 'max:100000'],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        if ($data['review_outcome'] === 'edited' && trim((string) ($data['body'] ?? '')) === '') {
            return back()->withErrors(['body' => '编辑后采用时必须保留有效的回复内容。']);
        }
        if ($data['review_outcome'] !== 'rejected' && ! $this->draftCurrent(Conversation::findOrFail($id), $conversationDraft)) {
            return back()->withErrors(['review_outcome' => '草稿依据已变化或失效，请重新生成后审核。']);
        }
        if ($conversationDraft->status === 'blocked' && $data['review_outcome'] !== 'rejected') {
            return back()->withErrors(['review_outcome' => '被安全门禁阻断的 AI Draft 不能直接采用；请拒绝后另行人工起草。']);
        }
        DB::transaction(function () use ($conversationDraft, $data, $id): void {
            $conversationDraft = ConversationDraft::lockForUpdate()->findOrFail($conversationDraft->id);
            app(TicketAssistanceFeedbackRecorder::class)->record(
                Conversation::findOrFail($id), (int) Admin::user()->id,
                ['target' => 'draft', 'kind' => $data['review_outcome'], 'body' => (string) ($data['review_notes'] ?? '')],
                $conversationDraft->workItem,
                ['draft_id' => $conversationDraft->id, 'before' => $conversationDraft->body,
                    'after' => $data['review_outcome'] === 'edited' ? trim((string) $data['body']) : $conversationDraft->body,
                    'metadata' => $conversationDraft->metadata],
            );
            $conversationDraft->update([
                'body' => $data['review_outcome'] === 'edited'
                    ? trim((string) $data['body'])
                    : $conversationDraft->body,
                'status' => $data['review_outcome'] === 'rejected' ? 'rejected' : 'draft',
                'review_outcome' => $data['review_outcome'],
                'review_notes' => trim((string) ($data['review_notes'] ?? '')) ?: null,
                'reviewed_by' => (int) Admin::user()->id,
                'reviewed_at' => now(),
                'updated_by' => (int) Admin::user()->id,
            ]);
            if ($conversationDraft->workItem !== null) {
                $conversationDraft->workItem->update(['result' => [
                    ...((array) $conversationDraft->workItem->result),
                    'human_review' => [
                        'outcome' => $data['review_outcome'],
                        'notes' => trim((string) ($data['review_notes'] ?? '')) ?: null,
                        'reviewed_by' => (int) Admin::user()->id,
                        'reviewed_at' => $conversationDraft->reviewed_at?->toIso8601String(),
                    ],
                ]]);
            }

        });
        admin_toastr('AI Draft 人工审核结果已保存；仍未发送到 HelpDesk。', 'success');

        return back();
    }

    public function verifyCancellation(Request $request, int $id, CancellationCaseVerifier $verifier): RedirectResponse
    {
        $ticket = Conversation::findOrFail($id);
        $data = $request->validate([
            'order_no' => ['nullable', 'string', 'max:100'],
        ]);
        $result = $verifier->verify($ticket, $data['order_no'] ?? null);
        $type = $result['status'] === 'failed' ? 'error' : ($result['status'] === 'not_found' ? 'warning' : 'success');
        admin_toastr($result['message'], $type);

        return back();
    }

    public function reviewCase(Request $request, int $id, int $case): RedirectResponse
    {
        $decisionCase = DecisionCase::where('conversation_id', $id)->findOrFail($case);
        $data = $request->validate([
            'review_status' => ['required', Rule::in(['confirmed', 'corrected', 'rejected'])],
            'primary_category' => ['nullable', 'string', 'max:100'],
            'primary_problem_type' => ['nullable', 'string', 'max:150'],
            'case_risk' => ['required', Rule::in(['normal', 'high', 'critical'])],
            'case_summary' => ['nullable', 'string', 'max:5000'],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $decisionCase->update([
            ...$data,
            'reviewed_by' => Admin::user()->id,
            'reviewed_at' => now(),
        ]);

        admin_toastr('案例审核结果已保存。', 'success');

        return back();
    }

    public function reviewNode(Request $request, int $id, int $node): RedirectResponse
    {
        $decisionNode = DecisionNode::whereHas(
            'decisionCase',
            fn (Builder $query) => $query->where('conversation_id', $id),
        )->findOrFail($node);
        $data = $request->validate([
            'review_status' => ['required', Rule::in(['confirmed', 'corrected', 'rejected'])],
            'scenario_key' => ['nullable', 'string', 'max:150'],
            'request_summary' => ['required', 'string', 'max:5000'],
            'observed_decision' => ['required', Rule::in(['accepted', 'rejected', 'conditional', 'alternative', 'escalated', 'unknown'])],
            'observed_action' => ['nullable', 'string', 'max:5000'],
            'stated_reason' => ['nullable', 'string', 'max:5000'],
            'observed_outcome' => ['nullable', 'string', 'max:5000'],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $decisionNode->update([
            ...$data,
            'reviewed_by' => Admin::user()->id,
            'reviewed_at' => now(),
        ]);

        admin_toastr('决策节点审核结果已保存。', 'success');

        return back();
    }

    private function draftCurrent(Conversation $ticket, ConversationDraft $draft): bool
    {
        if ($draft->source === 'openai_agents') {
            return $draft->workItem !== null
                && app(\App\Services\Agents\TicketAgentWorkflow::class)->current($ticket, $draft->workItem);
        }
        if (data_get($draft->metadata, 'workflow_key') === N8nTicketDraftService::INVESTIGATION_KEY
            && ($draft->workItem === null || ! $this->workItemCurrent($ticket, $draft->workItem))) {
            return false;
        }
        $hash = data_get($draft->metadata, 'workbench_input_hash');
        if (! is_string($hash)) {
            return false;
        }
        $workbench = app(TicketOrderWorkbench::class);
        $snapshot = $ticket->latestOrderContext()->first();

        return hash_equals($hash, $workbench->inputHash($ticket))
            && (data_get($draft->metadata, 'requires_order') === false || $snapshot === null || ($workbench->isFresh($ticket, $snapshot)
                && (data_get($draft->metadata, 'order_context_hash') !== null
                    ? $snapshot->response_hash === data_get($draft->metadata, 'order_context_hash')
                    : $snapshot->id === data_get($draft->metadata, 'order_context_id'))));
    }

    private function workItemCurrent(Conversation $ticket, WorkItem $item): bool
    {
        if ($item->workflow_key === \App\Services\Agents\TicketAgentWorkflow::KEY) {
            return app(\App\Services\Agents\TicketAgentWorkflow::class)->current($ticket, $item);
        }
        if ($item->workflow_key !== N8nTicketDraftService::INVESTIGATION_KEY) {
            return true;
        }
        try {
            $captured = app(TicketDraftInput::class)->capture($ticket,
                (int) data_get($item->input_snapshot, 'requested_by'), $item->workflow_key, $item->workflow_version);

            return hash_equals((string) $item->input_hash, $captured['hash']);
        } catch (Throwable) {
            return false;
        }
    }

    private function applyFilters(Builder $query, Request $request, ?string $agentId): void
    {
        $view = (string) $request->query('view', 'to_handle');
        $silo = $request->query('silo');
        if ($silo !== null && $silo !== '') {
            $query->where('silo', $silo);
        } elseif ($view === 'recent') {
            $query->where('silo', 'tickets');
        }

        if ($view === 'to_handle') {
            $query->where('silo', 'tickets')
                ->where('status', 'open')
                ->whereNull('assignment_snapshot->agent->ID');
        } elseif ($view === 'my_open') {
            $query->where('silo', 'tickets')->where('status', 'open');
            $agentId === null
                ? $query->whereRaw('1 = 0')
                : $query->where('assignment_snapshot->agent->ID', $agentId);
        } elseif ($view === 'needs_review') {
            $query->where(function (Builder $query): void {
                $query->whereHas('analysis', fn (Builder $analysis) => $analysis->where('needs_review', true))
                    ->orWhereHas('decisionCase', fn (Builder $case) => $case->where('review_status', 'candidate'));
            });
        } elseif ($view === 'has_case') {
            $query->whereHas('decisionCase');
        } elseif ($view === 'no_case') {
            $query->whereDoesntHave('decisionCase');
        } elseif ($view === 'high_risk') {
            $query->whereHas('decisionCase', fn (Builder $case) => $case->whereIn('case_risk', ['high', 'critical']));
        }

        foreach (['status', 'site_key'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }
        if ($request->filled('problem_type')) {
            $query->whereHas('analysis', fn (Builder $analysis) => $analysis->where('primary_problem_type', $request->query('problem_type')));
        }
        if ($request->filled('case_status')) {
            $query->whereHas('decisionCase', fn (Builder $case) => $case->where('review_status', $request->query('case_status')));
        }
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->query('keyword'));
            $query->where(function (Builder $query) use ($keyword): void {
                $query->where('external_id', 'like', "%{$keyword}%")
                    ->orWhere('external_short_id', 'like', "%{$keyword}%")
                    ->orWhere('subject', 'like', "%{$keyword}%")
                    ->orWhere('requester_email', 'like', "%{$keyword}%");
            });
        }
    }

    /** @return array<string, mixed> */
    private function counts(?string $agentId): array
    {
        $status = Conversation::where('silo', 'tickets')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $silos = Conversation::selectRaw('silo, count(*) as total')->groupBy('silo')->pluck('total', 'silo');

        return [
            'recent' => (int) ($silos['tickets'] ?? 0),
            'to_handle' => Conversation::where('silo', 'tickets')
                ->where('status', 'open')
                ->whereNull('assignment_snapshot->agent->ID')
                ->count(),
            'my_open' => $agentId === null ? 0 : Conversation::where('silo', 'tickets')
                ->where('status', 'open')
                ->where('assignment_snapshot->agent->ID', $agentId)
                ->count(),
            'status' => $status,
            'silo' => $silos,
            'needs_review' => Conversation::where(function (Builder $query): void {
                $query->whereHas('analysis', fn (Builder $analysis) => $analysis->where('needs_review', true))
                    ->orWhereHas('decisionCase', fn (Builder $case) => $case->where('review_status', 'candidate'));
            })->count(),
            'has_case' => Conversation::has('decisionCase')->count(),
            'high_risk' => Conversation::whereHas('decisionCase', fn (Builder $case) => $case->whereIn('case_risk', ['high', 'critical']))->count(),
        ];
    }

    /** @return array{id: string, name: string}|null */
    private function currentAgent(): ?array
    {
        $configuredId = trim((string) config('services.helpdesk.current_agent_id'));
        $agents = Conversation::query()
            ->where('silo', 'tickets')
            ->where('status', 'open')
            ->get(['assignment_snapshot'])
            ->map(fn (Conversation $ticket): ?array => is_string(data_get($ticket->assignment_snapshot, 'agent.ID'))
                ? [
                    'id' => data_get($ticket->assignment_snapshot, 'agent.ID'),
                    'name' => (string) data_get($ticket->assignment_snapshot, 'agent.name', '当前客服'),
                ]
                : null)
            ->filter()
            ->unique('id')
            ->values();

        if ($configuredId !== '') {
            return $agents->firstWhere('id', $configuredId) ?? ['id' => $configuredId, 'name' => '当前客服'];
        }

        return $agents->count() === 1 ? $agents->first() : null;
    }
}
