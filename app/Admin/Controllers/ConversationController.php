<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationDraft;
use App\Models\DecisionCase;
use App\Models\DecisionNode;
use App\Services\Cancellations\CancellationCaseVerifier;
use App\Services\N8n\N8nTicketDraftWorkflowClient;
use App\Services\N8n\WorkflowRegistry;
use App\Services\TicketWork\N8nTicketDraftService;
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
            ->with(['channelAccount', 'analysis', 'decisionCase'])
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
            'labels',
            'events' => fn ($query) => $query->with('attachments')->orderBy('occurred_at')->orderBy('id'),
            'decisionCase.nodes.evidence.event',
            'drafts' => fn ($query) => $query->whereIn('status', ['draft', 'blocked'])->latest('updated_at'),
            'runtimeCaseSource.customerCase.workItems' => fn ($query) => $query
                ->where('work_type', TicketWorkItemManager::WORK_TYPE)->latest('id'),
            'latestCancellationContext',
        ])->findOrFail($id);

        return $content
            ->title($ticket->subject ?: 'Ticket '.$ticket->external_short_id)
            ->description($ticket->external_short_id ?: $ticket->external_id)
            ->body(view('admin.tickets.show', compact('ticket')));
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

        ConversationDraft::updateOrCreate(
            [
                'conversation_id' => $ticket->id,
                'created_by' => $userId,
                'source' => 'manual',
                'status' => 'draft',
            ],
            [
                'body' => trim((string) ($data['body'] ?? '')),
                'is_private' => (bool) ($data['is_private'] ?? false),
                'status_after_send' => $data['status_after_send'] ?? null,
                'updated_by' => $userId,
            ],
        );

        admin_toastr('草稿已保存，仅保存在本地。', 'success');

        return back();
    }

    public function generateDraft(
        int $id,
        TicketWorkItemManager $workItems,
        N8nTicketDraftWorkflowClient $n8n,
        WorkflowRegistry $workflows,
    ): RedirectResponse {
        $ticket = Conversation::query()->with('analysis')->findOrFail($id);
        if ($ticket->silo !== 'tickets' || ! in_array($ticket->status, ['open', 'pending', 'onhold'], true)) {
            admin_toastr('只有当前工作区中仍需处理的 Ticket 可以创建在线 Draft WorkItem。', 'warning');

            return back();
        }
        try {
            $workflows->runnable(N8nTicketDraftService::WORKFLOW_KEY, N8nTicketDraftService::WORKFLOW_VERSION);
            $item = $workItems->create(
                $ticket,
                (int) Admin::user()->id,
                N8nTicketDraftService::WORKFLOW_KEY,
                N8nTicketDraftService::WORKFLOW_VERSION,
            );
            if ($item->wasRecentlyCreated) {
                $n8n->trigger($item);
                admin_toastr('Ticket Draft 已进入 n8n shadow 工作流，不会发送到 HelpDesk。', 'success');
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

        return back();
    }

    public function reviewDraft(Request $request, int $id, int $draft): RedirectResponse
    {
        $conversationDraft = ConversationDraft::query()
            ->where('conversation_id', $id)
            ->where('source', 'n8n')
            ->findOrFail($draft);
        $data = $request->validate([
            'review_outcome' => ['required', Rule::in(['accepted_as_is', 'edited', 'rejected'])],
            'body' => ['nullable', 'string', 'max:100000'],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        if ($data['review_outcome'] === 'edited' && trim((string) ($data['body'] ?? '')) === '') {
            return back()->withErrors(['body' => '编辑后采用时必须保留有效的回复内容。']);
        }
        if ($conversationDraft->status === 'blocked' && $data['review_outcome'] !== 'rejected') {
            return back()->withErrors(['review_outcome' => '被安全门禁阻断的 AI Draft 不能直接采用；请拒绝后另行人工起草。']);
        }
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
