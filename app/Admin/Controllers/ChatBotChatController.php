<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ChannelAccount;
use App\Models\ChatBotChat;
use App\Models\ChatBotStory;
use Encore\Admin\Layout\Content;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ChatBotChatController extends Controller
{
    public function index(Request $request, Content $content): Content
    {
        $query = ChatBotChat::query()
            ->with(['channelAccount', 'story', 'events' => fn ($query) => $query
                ->where('author_type', 'user')
                ->orderBy('occurred_at')
                ->orderBy('id')])
            ->withCount(['events', 'ticketLinks']);

        $this->applyFilters($query, $request);

        $chats = $query
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return $content
            ->title('ChatBot Chats')
            ->description('会话归档与消息时间线')
            ->body(view('admin.chatbot.index', [
                'chats' => $chats,
                'filters' => $request->query(),
                'stories' => ChatBotStory::query()->orderBy('name')->get(['id', 'name']),
                'sources' => ChatBotChat::query()->whereNotNull('source')->distinct()->orderBy('source')->pluck('source'),
                'retentionStatuses' => ChatBotChat::query()->distinct()->orderBy('retention_status')->pluck('retention_status'),
                'lastSyncedAt' => ChannelAccount::where('channel', 'chatbot')->max('last_synced_at'),
                'counts' => [
                    'all' => ChatBotChat::count(),
                    'with_user_input' => ChatBotChat::whereHas('events', fn (Builder $query) => $query->where('author_type', 'user'))->count(),
                    'with_ticket' => ChatBotChat::has('ticketLinks')->count(),
                ],
            ]));
    }

    public function show(int $id, Content $content): Content
    {
        $chat = ChatBotChat::query()->with([
            'channelAccount',
            'story',
            'events' => fn ($query) => $query->orderBy('occurred_at')->orderBy('id'),
            'ticketLinks.conversation',
        ])->findOrFail($id);

        return $content
            ->title('Chat '.$chat->external_id)
            ->description($chat->story?->name ?: 'Unknown Story')
            ->body(view('admin.chatbot.show', compact('chat')));
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        foreach (['source', 'retention_status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }
        if ($request->filled('story_id')) {
            $query->where('chatbot_story_id', $request->integer('story_id'));
        }
        if ($request->boolean('with_user_input')) {
            $query->whereHas('events', fn (Builder $events) => $events->where('author_type', 'user'));
        }
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->query('keyword'));
            $query->where(function (Builder $query) use ($keyword): void {
                $query->where('external_id', 'like', "%{$keyword}%")
                    ->orWhere('external_user_id', 'like', "%{$keyword}%")
                    ->orWhereHas('events', fn (Builder $events) => $events->where('body_text', 'like', "%{$keyword}%"));
            });
        }
    }
}
