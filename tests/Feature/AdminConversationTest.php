<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_list_filter_and_view_tickets(): void
    {
        $administrator = Administrator::create([
            'username' => 'admin-test',
            'password' => Hash::make('secret'),
            'name' => 'Admin Test',
        ]);
        $role = Role::where('slug', 'administrator')->firstOrFail();
        $administrator->roles()->attach($role);

        $account = ChannelAccount::create([
            'channel' => 'helpdesk',
            'external_account_id' => 'account-1',
            'display_name' => 'HelpDesk Main',
        ]);
        $ticket = Conversation::create([
            'channel_account_id' => $account->id,
            'site_key' => 'dressafford.com',
            'external_id' => 'ticket-external-1001',
            'external_short_id' => '1001',
            'subject' => 'Wedding dress delivery status',
            'status' => 'open',
            'priority' => 1,
            'silo' => 'tickets',
            'requester_email' => 'customer@example.com',
            'requester_snapshot' => [],
            'assignment_snapshot' => [],
            'raw_payload' => [],
        ]);
        Conversation::create([
            'channel_account_id' => $account->id,
            'site_key' => 'ellenbridals.com',
            'external_id' => 'ticket-external-1002',
            'external_short_id' => '1002',
            'subject' => 'Different ticket',
            'status' => 'closed',
            'silo' => 'archive',
            'raw_payload' => [],
        ]);
        ConversationEvent::create([
            'conversation_id' => $ticket->id,
            'external_id' => 'event-1',
            'event_type' => 'message',
            'author_type' => 'customer',
            'author_name' => 'Customer',
            'body_text' => 'Please tell me when my dress will arrive.',
            'content_hash' => hash('sha256', 'event-1'),
            'raw_payload' => [],
        ]);

        $this->actingAs($administrator, 'admin');

        $this->get('/admin/conversations')
            ->assertOk()
            ->assertSee('Wedding dress delivery status')
            ->assertSee('Different ticket');

        $this->get('/admin/conversations?site_key=dressafford.com&keyword=delivery')
            ->assertOk()
            ->assertSee('Wedding dress delivery status')
            ->assertDontSee('Different ticket');

        $this->get("/admin/conversations/{$ticket->id}")
            ->assertOk()
            ->assertSee('ticket-external-1001')
            ->assertSee('Please tell me when my dress will arrive.');
    }

    public function test_ticket_menu_is_created_by_migrations(): void
    {
        $this->assertDatabaseHas(config('admin.database.menu_table'), [
            'title' => 'Tickets',
            'uri' => 'conversations',
        ]);
    }
}
