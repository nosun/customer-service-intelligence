<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchTicketAssistance;
use App\Models\Conversation;
use App\Models\WorkItem;
use App\Services\N8n\WorkflowRegistry;
use App\Services\TicketWork\N8nTicketDraftService;
use App\Services\TicketWork\TicketAssistanceFeedbackRecorder;
use App\Services\TicketWork\TicketWorkItemManager;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class TicketAssistanceController extends Controller
{
    public function agent(Request $request, int $id, \App\Services\Agents\TicketAgentWorkflow $workflow): RedirectResponse
    {
        $ticket = Conversation::findOrFail($id);
        $data = $request->validate(['draft_guidance' => ['nullable', 'string', 'max:10000']]);
        try {
            if ($request->exists('draft_guidance')) {
                $text = trim((string) ($data['draft_guidance'] ?? '')) ?: null;
                if ($ticket->draft_guidance !== $text) {
                    app(TicketAssistanceFeedbackRecorder::class)->guidance($ticket, $text, (int) Admin::user()->id);
                    $ticket->refresh();
                }
            }
            $item = $workflow->create($ticket, (int) Admin::user()->id, (string) config('services.draft_ai.provider'));
            if ($item->wasRecentlyCreated) {
                \App\Jobs\RunTicketAgent::dispatch($item->id)->onConnection('background');
            }
            admin_toastr('Agents SDK 已安排调查、拟稿与独立复核，完成后保存本地待审核邮件。', 'success');
        } catch (Throwable) {
            if (isset($item) && $item->wasRecentlyCreated) {
                $item->update(['status' => 'failed', 'error_message' => 'Unable to dispatch the Ticket Agent.', 'finished_at' => now()]);
            }
            admin_toastr('无法启动 Ticket Agent，请检查案件状态和模型配置。', 'error');
        }

        return redirect(admin_url('conversations/'.$id).'#reply-draft');
    }

    public function feedback(Request $request, int $id, TicketAssistanceFeedbackRecorder $recorder): RedirectResponse
    {
        $ticket = Conversation::findOrFail($id);
        $data = $request->validate([
            'work_item_id' => ['nullable', 'integer', 'exists:work_items,id'],
            'target' => ['required', Rule::in(['analysis', 'advice'])],
            'kind' => ['required', Rule::in(['fact', 'decision', 'correction', 'accepted', 'rejected'])],
            'body' => ['required', 'string', 'max:4000'],
            'knowledge_candidate' => ['sometimes', 'boolean'],
            'regenerate' => ['sometimes', 'boolean'],
        ]);
        DB::transaction(function () use ($ticket, $data, $recorder): void {
            $recorder->record($ticket, (int) Admin::user()->id, $data, isset($data['work_item_id']) ? WorkItem::findOrFail($data['work_item_id']) : null);
            if (in_array($data['kind'], ['fact', 'decision', 'correction'], true)) {
                $ticket->update(['draft_guidance_updated_at' => now(), 'draft_guidance_updated_by' => Admin::user()->id]);
            }
        });
        if ($request->boolean('regenerate')) {
            if ($ticket->silo !== 'tickets' || ! in_array($ticket->status, ['open', 'pending', 'onhold'], true)) {
                return back()->withErrors(['feedback' => '反馈已保存，已关闭或归档的 Ticket 不重新派工。']);
            }
            try {
                app(WorkflowRegistry::class)->runnable(N8nTicketDraftService::INVESTIGATION_KEY, N8nTicketDraftService::INVESTIGATION_VERSION);
                $item = app(TicketWorkItemManager::class)->create($ticket->fresh(), (int) Admin::user()->id, N8nTicketDraftService::INVESTIGATION_KEY, N8nTicketDraftService::INVESTIGATION_VERSION);
                if ($item->wasRecentlyCreated) {
                    DispatchTicketAssistance::dispatch($item->id)->afterCommit();
                }
            } catch (Throwable $exception) {
                report($exception);

                return back()->withErrors(['feedback' => '反馈已保存；重新分析未能启动，请检查工作流连接后重试。']);
            }
        }
        admin_toastr('反馈已保存，仅影响当前 Ticket；知识候选需另行审核。', 'success');

        return back();
    }
}
