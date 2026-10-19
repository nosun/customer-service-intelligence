<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AiWorkflowReplayRun;
use App\Services\N8n\WorkflowReplayReviewServiceRegistry;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class WorkflowReplayReviewController extends Controller
{
    public function index(Content $content): Content
    {
        $runs = AiWorkflowReplayRun::query()->with('workflow')->latest('id')->paginate(30);

        return $content
            ->title('Workflow 回放审核')
            ->description('事实与 Policy 人工门禁')
            ->body(view('admin.workflow-replay-reviews.index', compact('runs')));
    }

    public function show(Request $request, int $id, Content $content): Content
    {
        $run = AiWorkflowReplayRun::query()->with('workflow')->findOrFail($id);
        $cases = $run->cases()->orderBy('case_key');
        if ($request->query('review') === 'pending') {
            $cases->where(function ($query): void {
                $query->whereNull('fact_errors')->orWhereNull('policy_violations');
            });
        } elseif ($request->query('review') === 'issues') {
            $cases->where(function ($query): void {
                $query->where('fact_errors', '>', 0)->orWhere('policy_violations', '>', 0);
            });
        }

        return $content
            ->title('回放审核 #'.$run->id)
            ->description($run->workflow->workflow_key.' @ '.$run->workflow->workflow_version)
            ->body(view('admin.workflow-replay-reviews.show', [
                'run' => $run,
                'cases' => $cases->paginate(10)->withQueryString(),
                'filter' => $request->query('review', 'all'),
            ]));
    }

    public function review(Request $request, int $runId, int $caseId, WorkflowReplayReviewServiceRegistry $reviews): RedirectResponse
    {
        $run = AiWorkflowReplayRun::findOrFail($runId);
        $case = $run->cases()->findOrFail($caseId);
        $data = $request->validate([
            'fact_errors' => ['required', 'integer', 'min:0', 'max:100'],
            'policy_violations' => ['required', 'integer', 'min:0', 'max:100'],
            'accepted_as_is' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $case->update([
            ...$data,
            'accepted_as_is' => array_key_exists('accepted_as_is', $data) ? (bool) $data['accepted_as_is'] : null,
            'reviewed_by' => Admin::user()->id,
            'reviewed_at' => now(),
        ]);
        $service = $reviews->forRun($run);
        $service->invalidateGate($run);
        $service->refreshProgress($run);
        admin_toastr("{$case->case_key} 的人工复核已保存。", 'success');

        return back();
    }

    public function gate(int $id, WorkflowReplayReviewServiceRegistry $reviews): RedirectResponse
    {
        $run = AiWorkflowReplayRun::findOrFail($id);
        if ($run->reviewed_count !== $run->case_count) {
            return back()->withErrors(['gate' => '必须完成全部案例的事实与 Policy 人工复核。']);
        }
        try {
            $report = $reviews->forRun($run)->gate($run);
        } catch (Throwable $exception) {
            return back()->withErrors(['gate' => $exception->getMessage()]);
        }
        admin_toastr($report['passed'] ? '发布门禁已通过。' : '发布门禁未通过，请查看失败指标。', $report['passed'] ? 'success' : 'warning');

        return back();
    }
}
