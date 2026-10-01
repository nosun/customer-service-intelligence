<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\ChannelSyncRun;
use App\Models\Conversation;
use App\Models\ConversationAttachment;
use App\Models\ConversationEvent;
use App\Services\HelpDesk\HelpDeskImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class HelpDeskImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_helpdesk_fixture_import_is_idempotent(): void
    {
        $arguments = [
            '--fixture' => base_path('tests/fixtures/helpdesk-tickets.json'),
            '--site-key' => 'dressafford.com',
        ];

        $this->artisan('helpdesk:sync', $arguments)->assertSuccessful();
        $this->artisan('helpdesk:sync', $arguments)->assertSuccessful();

        $this->assertDatabaseCount('channel_accounts', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_events', 3);
        $this->assertDatabaseCount('conversation_attachments', 1);
        $this->assertDatabaseCount('channel_sync_runs', 2);
        $this->assertSame('helpdesk', ChannelAccount::firstOrFail()->channel);
        $this->assertSame('customer@example.com', Conversation::firstOrFail()->requester_email);
        $this->assertArrayNotHasKey('events', Conversation::firstOrFail()->raw_payload);
        $this->assertSame(2, ConversationEvent::where('event_type', 'message')->count());
        $this->assertSame('status.pdf', ConversationAttachment::firstOrFail()->filename);
        $this->assertSame(['completed', 'completed'], ChannelSyncRun::orderBy('id')->pluck('status')->all());
    }

    public function test_import_requires_an_account_id_when_response_is_empty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HelpDesk account ID is missing.');

        app(HelpDeskImportService::class)->import([], '', null, []);
    }

    public function test_non_array_attachments_are_ignored(): void
    {
        $ticket = json_decode(file_get_contents(base_path('tests/fixtures/helpdesk-tickets.json')), true, 512, JSON_THROW_ON_ERROR)['tickets'][0];
        $ticket['events'][0]['attachments'] = false;

        app(HelpDeskImportService::class)->import([$ticket], '', null, []);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_events', 3);
    }

    public function test_status_transition_stores_the_new_status(): void
    {
        $contents = file_get_contents(base_path('tests/fixtures/helpdesk-tickets.json'));
        $this->assertIsString($contents);
        $ticket = json_decode($contents, true, 512, JSON_THROW_ON_ERROR)['tickets'][0];
        $ticket['events'][0]['status'] = ['old' => 'open', 'new' => 'pending'];

        app(HelpDeskImportService::class)->import([$ticket], '', null, []);

        $event = ConversationEvent::where('external_id', $ticket['events'][0]['ID'])->firstOrFail();
        $this->assertSame('pending', $event->status_value);
    }

    public function test_empty_response_reuses_the_existing_site_account(): void
    {
        $account = ChannelAccount::create([
            'channel' => 'helpdesk',
            'site_key' => 'dressafford.com',
            'external_account_id' => 'license-1',
        ]);

        app(HelpDeskImportService::class)->import([], 'different-api-account', 'dressafford.com', []);

        $this->assertDatabaseCount('channel_accounts', 1);
        $this->assertSame($account->id, ChannelSyncRun::firstOrFail()->channel_account_id);
    }

    public function test_ticket_site_is_derived_from_the_assigned_team(): void
    {
        $contents = file_get_contents(base_path('tests/fixtures/helpdesk-tickets.json'));
        $this->assertIsString($contents);
        $ticket = json_decode($contents, true, 512, JSON_THROW_ON_ERROR)['tickets'][0];
        $ticket['assignment']['team']['name'] = 'ellen bridals-桌面';

        app(HelpDeskImportService::class)->import([$ticket], '', null, []);

        $this->assertSame('ellenbridals.com', Conversation::firstOrFail()->site_key);
    }
}
