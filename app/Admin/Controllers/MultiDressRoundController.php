<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CustomerContactEffect;
use App\Models\CustomerContactRound;
use App\Services\CustomerContacts\MultiDressConsoleExecutor;
use App\Services\CustomerContacts\MultiDressRoundManager;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MultiDressRoundController extends Controller
{
    public function index(Content $content)
    {
        $this->allow('view');
        if (! Schema::hasTable('customer_contact_rounds')) {
            return $content->title('多件确认首轮')->body('首轮台账尚未安装，请按运行手册完成迁移。');
        }

        return $content->title('多件确认首轮')->body(view('admin.multi-dress-rounds.index', [
            'rounds' => CustomerContactRound::latest('id')->paginate(30)]));
    }

    public function show(int $id, Content $content)
    {
        $this->allow('view');
        $round = CustomerContactRound::with(['effects', 'events'])->findOrFail($id);
        $mail = $round->approved_mail_ref && Storage::disk('local')->exists($round->approved_mail_ref)
            ? json_decode(Storage::disk('local')->get($round->approved_mail_ref), true) : null;

        return $content->title('多件确认：'.$round->order_number)->body(view('admin.multi-dress-rounds.show', compact('round', 'mail')));
    }

    public function hold(Request $request, int $id)
    {
        $this->allow('edit');
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|max:255']);
        DB::transaction(function () use ($id, $data) {
            $round = CustomerContactRound::lockForUpdate()->findOrFail($id);
            if ($round->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => '记录已变化，请刷新后接管。']);
            }
            $state = in_array($round->communication_state, ['sending', 'sent', 'send_unknown']) ? $round->communication_state : 'manual';
            $round->update(['human_hold_at' => now(), 'human_hold_reason' => $data['reason'], 'waiting_state' => 'held',
                'communication_state' => $state, 'version' => $round->version + 1]);
            $round->events()->create(['event_key' => 'hold:'.Str::uuid(), 'event_type' => 'human_hold',
                'actor' => 'admin:'.Admin::user()->id, 'occurred_at' => now(), 'evidence' => ['reason' => $data['reason']]]);
        });

        return redirect(admin_url('multi-dress-rounds/'.$id));
    }

    public function draft(int $id, MultiDressRoundManager $manager)
    {
        $this->allow('edit');
        $manager->requestWork(CustomerContactRound::findOrFail($id));

        return redirect(admin_url('multi-dress-rounds/'.$id));
    }

    public function previewNote(int $id, MultiDressConsoleExecutor $executor)
    {
        $this->allow('execute');
        $executor->previewNote($id);

        return redirect(admin_url('multi-dress-rounds/'.$id));
    }

    public function confirm(Request $request, int $id, int $effectId, MultiDressConsoleExecutor $executor)
    {
        $this->allow('execute');
        $data = $request->validate(['preview_hash' => 'required|string|size:64']);
        CustomerContactEffect::where('round_id', $id)->findOrFail($effectId);
        $executor->confirm($effectId, Admin::user(), $data['preview_hash']);

        return redirect(admin_url('multi-dress-rounds/'.$id));
    }

    private function allow(string $action): void
    {
        abort_unless(Admin::user()?->isAdministrator() || Admin::user()?->can('customer-contacts.'.$action), 403);
    }
}
