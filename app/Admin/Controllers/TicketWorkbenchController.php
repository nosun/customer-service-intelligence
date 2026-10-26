<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\SyncHelpDeskTickets;
use App\Models\ActionProposal;
use App\Models\Conversation;
use App\Services\TicketWork\TicketAssistanceFeedbackRecorder;
use App\Services\TicketWork\TicketOrderActionService;
use App\Services\TicketWork\TicketOrderWorkbench;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Throwable;

class TicketWorkbenchController extends Controller
{
    public function sync(): RedirectResponse
    {
        $state = Cache::get('helpdesk:sync-state', []);
        if (in_array($state['status'] ?? null, ['queued', 'running'], true)) {
            admin_toastr('HelpDesk 同步已在队列中或正在运行。', 'info');

            return back();
        }
        Cache::put('helpdesk:sync-state', ['status' => 'queued', 'requested_at' => now()->toIso8601String()], 3600);
        try {
            SyncHelpDeskTickets::dispatch()->onConnection('background');
            admin_toastr('已安排立即同步，复用现有增量同步命令。', 'success');
        } catch (Throwable) {
            Cache::forget('helpdesk:sync-state');
            admin_toastr('无法启动同步，请查看应用日志。', 'error');
        }

        return back();
    }

    public function guidance(Request $request, int $id): RedirectResponse
    {
        $ticket = Conversation::findOrFail($id);
        $data = $request->validate(['draft_guidance' => ['nullable', 'string', 'max:10000']]);
        $text = trim((string) ($data['draft_guidance'] ?? '')) ?: null;
        if ($ticket->draft_guidance !== $text) {
            app(TicketAssistanceFeedbackRecorder::class)->guidance($ticket, $text, (int) Admin::user()->id);
            $ticket->refresh();
        }
        admin_toastr('回复指南已保存，仅影响当前 Ticket。', 'success');

        return back();
    }

    public function inspect(Request $request, int $id, TicketOrderWorkbench $workbench): RedirectResponse
    {
        $ticket = Conversation::findOrFail($id);
        $data = $request->validate(['order_no' => ['nullable', 'string', 'max:100']]);
        $snapshot = $workbench->inspect($ticket, filled($data['order_no'] ?? null) ? $data['order_no'] : null, (int) Admin::user()->id);
        admin_toastr($snapshot->lookup_status === 'found' ? '已刷新订单与工单事实，请查看处理建议。' : $snapshot->error_message, $snapshot->lookup_status === 'found' ? 'success' : 'warning');

        return redirect(admin_url('conversations/'.$id).'#order-workbench');
    }

    public function preview(Request $request, int $id, TicketOrderActionService $actions): RedirectResponse
    {
        $this->authorizeAction();
        $data = $request->validate([
            'action_type' => ['required', Rule::in(['cancel_order', 'standard_size_change'])],
            'item_id' => ['nullable', 'integer', 'min:1'],
            'size' => ['nullable', 'string', 'max:10'],
            'policy_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:2000'],
            'customer_confirmed' => ['accepted'], 'policy_confirmed' => ['accepted'],
        ]);
        try {
            $actions->preview(Conversation::findOrFail($id), $data['action_type'], isset($data['item_id']) ? (int) $data['item_id'] : null, $data['size'] ?? null, (int) $data['policy_id'], $data['reason'], (int) Admin::user()->id);
            admin_toastr('操作预览已保存，尚未执行。', 'success');
        } catch (Throwable $e) {
            admin_toastr($this->safeMessage($e), 'warning');
        }

        return back();
    }

    public function execute(Request $request, int $id, int $proposal, TicketOrderActionService $actions): RedirectResponse
    {
        $this->authorizeAction();
        $request->validate(['confirm_execution' => ['accepted']]);
        $ticket = Conversation::findOrFail($id);
        $action = ActionProposal::where('parameters->conversation_id', $id)->findOrFail($proposal);
        try {
            $result = $actions->execute($ticket, $action, (int) Admin::user()->id);
            admin_toastr('业务动作状态：'.$result->status.'；退款与客户通知另行处理。', $result->status === 'succeeded' ? 'success' : 'warning');
        } catch (Throwable $e) {
            admin_toastr($this->safeMessage($e), 'warning');
        }

        return back();
    }

    public function reconcile(int $id, int $proposal, TicketOrderActionService $actions): RedirectResponse
    {
        $this->authorizeAction();
        $ticket = Conversation::findOrFail($id);
        $action = ActionProposal::where('parameters->conversation_id', $id)->findOrFail($proposal);
        $result = $actions->reconcile($ticket, $action, (int) Admin::user()->id);
        admin_toastr('回读核验结果：'.$result->status.'，未重新提交业务操作。', 'info');

        return back();
    }

    private function authorizeAction(): void
    {
        abort_unless(Admin::user()->isAdministrator() || Admin::user()->can('ticket-order-actions'), 403);
    }

    private function safeMessage(Throwable $exception): string
    {
        return get_class($exception) === \RuntimeException::class ? mb_substr($exception->getMessage(), 0, 300) : '业务接口请求失败，请查看权限与接口状态后重新核查。';
    }
}
