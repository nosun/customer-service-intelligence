<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\FollowUp;
use App\Services\FollowUps\FollowUpManager;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FollowUpController extends Controller
{
    public function index(Request $request, Content $content, FollowUpManager $manager): Content
    {
        $filters = $request->validate([
            'view' => ['nullable', 'string'], 'search' => ['nullable', 'string', 'max:200'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $ticket = $request->filled('conversation_id')
            ? Conversation::where('silo', 'tickets')->findOrFail($request->integer('conversation_id')) : null;

        return $content->title('跟进台账')->description('记录进展，安排下一次处理')
            ->body(view('admin.follow-ups.index', [
                'items' => $manager->query($filters)->paginate(30)->withQueryString(),
                'filters' => $filters, 'ticket' => $ticket,
                'defaultDue' => now(FollowUp::TIMEZONE)->addDay()->setTime(10, 0)->format('Y-m-d\TH:i'),
            ]));
    }

    public function store(Request $request, FollowUpManager $manager): RedirectResponse
    {
        $input = $request->all();
        if (! $request->filled('next_follow_up_at')) {
            unset($input['next_follow_up_at']);
        }
        $input['owner'] = $request->input('owner') ?: Admin::user()->name;
        $item = $manager->create($input, $this->actor());

        return redirect(admin_url('follow-ups/'.$item->id))->with('follow_up_success', '已加入跟进。');
    }

    public function show(int $id, Content $content): Content
    {
        $item = FollowUp::with('conversation')->findOrFail($id);

        return $content->title('跟进 #'.$item->id)->description(e($item->title))
            ->body(view('admin.follow-ups.show', [
                'item' => $item, 'entries' => $item->entries()->latest('id')->paginate(30),
            ]));
    }

    public function update(Request $request, int $id, FollowUpManager $manager): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $manager->update($id, $request->all(), (int) $data['version'], $this->actor());

        return redirect(admin_url('follow-ups/'.$id))->with('follow_up_success', '进展已保存。');
    }

    private function actor(): string
    {
        return 'admin:'.Admin::user()->id.' '.Admin::user()->name;
    }
}
