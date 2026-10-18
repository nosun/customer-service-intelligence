<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ActionProposal;
use App\Models\CustomerCase;
use App\Services\Work\ActionProposalService;
use App\Services\Work\CustomerCaseManager;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class CustomerCaseController extends Controller
{
    public function index(Request $request, Content $content): Content
    {
        $query = CustomerCase::query()->withCount(['sources', 'workItems', 'actionProposals'])->latest('updated_at');
        foreach (['status', 'case_type', 'queue', 'risk_level'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->query('keyword'));
            $query->where(fn ($query) => $query->where('case_key', 'like', "%{$keyword}%")
                ->orWhere('title', 'like', "%{$keyword}%"));
        }

        return $content
            ->title('客服 Case')
            ->description('工作项、SLA、交接、动作审批与结果')
            ->body(view('admin.customer-cases.index', [
                'cases' => $query->paginate(40)->withQueryString(),
                'filters' => $request->query(),
            ]));
    }

    public function show(int $id, Content $content): Content
    {
        $case = CustomerCase::query()->with([
            'sources',
            'workItems' => fn ($query) => $query->latest('id'),
            'actionProposals' => fn ($query) => $query->latest('id'),
            'handoffs' => fn ($query) => $query->latest('id'),
        ])->findOrFail($id);

        return $content
            ->title($case->title)
            ->description($case->case_key)
            ->body(view('admin.customer-cases.show', compact('case')));
    }

    public function handoff(Request $request, int $id, CustomerCaseManager $cases): RedirectResponse
    {
        $case = CustomerCase::findOrFail($id);
        $data = $request->validate([
            'queue' => ['nullable', 'string', 'max:100'],
            'owner_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $cases->handoff(
            $case,
            $data['queue'] ?? null,
            isset($data['owner_id']) ? (int) $data['owner_id'] : null,
            $data['reason'],
            (int) Admin::user()->id,
        );
        admin_toastr('Case 已交接并记录审计。', 'success');

        return back();
    }

    public function outcome(Request $request, int $id, CustomerCaseManager $cases): RedirectResponse
    {
        $case = CustomerCase::findOrFail($id);
        $data = $request->validate([
            'outcome_type' => ['required', 'string', 'max:100'],
            'summary' => ['required', 'string', 'max:5000'],
        ]);
        $cases->recordOutcome($case, $data['outcome_type'], [
            'summary' => $data['summary'],
            'recorded_by' => (int) Admin::user()->id,
        ]);
        admin_toastr('Case Outcome 已记录。', 'success');

        return back();
    }

    public function reviewAction(
        Request $request,
        int $caseId,
        int $proposalId,
        ActionProposalService $actions,
    ): RedirectResponse {
        $case = CustomerCase::findOrFail($caseId);
        $proposal = ActionProposal::query()->where('customer_case_id', $case->id)->findOrFail($proposalId);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        try {
            if ($data['decision'] === 'approve') {
                $actions->approve($proposal, (int) Admin::user()->id, $data['review_notes'] ?? null);
                admin_toastr('动作提案已批准，但尚未执行。', 'success');
            } else {
                $actions->reject($proposal, (int) Admin::user()->id, (string) ($data['review_notes'] ?? ''));
                admin_toastr('动作提案已驳回。', 'success');
            }
        } catch (RuntimeException $exception) {
            return back()->withErrors(['action' => $exception->getMessage()]);
        }

        return back();
    }
}
