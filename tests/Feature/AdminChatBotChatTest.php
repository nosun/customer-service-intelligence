<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\ChatBotChat;
use App\Models\ChatBotChatEvent;
use App\Models\ChatBotStory;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminChatBotChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_list_filter_and_view_chatbot_chats(): void
    {
        $administrator = $this->administrator();
        $account = ChannelAccount::create([
            'channel' => 'chatbot',
            'external_account_id' => 'primary',
            'display_name' => 'ChatBot',
            'last_synced_at' => now(),
        ]);
        $story = ChatBotStory::create([
            'channel_account_id' => $account->id,
            'external_id' => 'story-alberich',
            'name' => 'Alberich Bot',
            'published' => true,
            'integrations' => [],
            'fetched_at' => now(),
        ]);
        $chat = $this->chat($account, $story, 'chat-visible', 'openwidget', 'unreviewed');
        ChatBotChatEvent::create([
            'chatbot_chat_id' => $chat->id,
            'external_id' => 'message-1:input',
            'occurred_at' => now()->subMinute(),
            'author_type' => 'user',
            'event_type' => 'INPUT_TEXT',
            'body_text' => 'Where is order DRW123? <script>alert(1)</script>',
            'score' => 0.91,
            'content_hash' => hash('sha256', 'message-1'),
            'raw_payload' => [],
        ]);
        ChatBotChatEvent::create([
            'chatbot_chat_id' => $chat->id,
            'external_id' => 'message-2:response',
            'occurred_at' => now(),
            'author_type' => 'bot',
            'event_type' => 'BOT_RESPONSE',
            'body_text' => 'Please provide your email address.',
            'content_hash' => hash('sha256', 'message-2'),
            'raw_payload' => [],
        ]);
        $hidden = $this->chat($account, $story, 'chat-hidden', 'app', 'noise');

        $this->actingAs($administrator, 'admin');

        $this->get('/admin/chatbot-chats')
            ->assertOk()
            ->assertSee('chat-visible')
            ->assertSee('chat-hidden')
            ->assertSee('Alberich Bot')
            ->assertSee('Where is order DRW123?');

        $this->get('/admin/chatbot-chats?source=openwidget&retention_status=unreviewed&with_user_input=1&keyword=DRW123')
            ->assertOk()
            ->assertSee('chat-visible')
            ->assertDontSee('chat-hidden');

        $this->get("/admin/chatbot-chats/{$chat->id}")
            ->assertOk()
            ->assertSee('Where is order DRW123?')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Please provide your email address.')
            ->assertSee('customer@example.com')
            ->assertSee('dressafford.com');

        $this->get("/admin/chatbot-chats/{$hidden->id}")->assertOk();
    }

    public function test_guest_cannot_access_chatbot_chats(): void
    {
        $this->get('/admin/chatbot-chats')->assertRedirect('/admin/auth/login');
    }

    public function test_chatbot_menu_is_created_by_migrations(): void
    {
        $this->assertDatabaseHas(config('admin.database.menu_table'), [
            'title' => 'ChatBot Chats',
            'uri' => 'chatbot-chats',
        ]);
    }

    private function administrator(): Administrator
    {
        $administrator = Administrator::create([
            'username' => 'chatbot-admin-test',
            'password' => Hash::make('secret'),
            'name' => 'ChatBot Admin Test',
        ]);
        $role = Role::where('slug', 'administrator')->firstOrFail();
        $administrator->roles()->attach($role);

        return $administrator;
    }

    private function chat(
        ChannelAccount $account,
        ChatBotStory $story,
        string $externalId,
        string $source,
        string $retentionStatus,
    ): ChatBotChat {
        return ChatBotChat::create([
            'channel_account_id' => $account->id,
            'chatbot_story_id' => $story->id,
            'external_id' => $externalId,
            'external_story_id' => $story->external_id,
            'external_user_id' => 'user-'.$externalId,
            'user_snapshot' => ['name' => 'Customer', 'email' => 'customer@example.com'],
            'source' => $source,
            'attributes' => ['default_url' => 'https://dressafford.com/product'],
            'started_at' => now()->subHour(),
            'updated_at_external' => now(),
            'message_count' => 2,
            'retention_status' => $retentionStatus,
            'raw_payload' => [],
            'synced_at' => now(),
        ]);
    }
}
