<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\ChannelSyncCursor;
use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\ConversationEvent;
use App\Models\ConversationEventAnalysis;
use App\Models\ConversationReplyCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HelpDeskIncrementalAndAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_incremental_sync_resumes_from_latest_local_update(): void
    {
        $account = ChannelAccount::create([
            'channel' => 'helpdesk',
            'external_account_id' => '12345',
        ]);
        Conversation::create([
            'channel_account_id' => $account->id,
            'external_id' => 'ticket-1',
            'silo' => 'tickets',
            'status' => 'open',
            'updated_at_external' => '2026-09-01T10:00:00Z',
            'raw_payload' => [],
        ]);
        Http::fake([
            '*' => Http::response([[
                'ID' => 'ticket-2',
                'licenseID' => 12345,
                'status' => 'open',
                'updatedAt' => '2026-09-02T10:00:00Z',
                'assignment' => ['team' => ['name' => 'Dressafford']],
                'events' => [],
            ]], 200),
        ]);

        $this->artisan('helpdesk:sync-incremental', [
            '--silo' => ['tickets'],
            '--status' => ['open'],
        ])->assertSuccessful();

        $this->assertDatabaseCount('conversations', 2);
        $cursor = ChannelSyncCursor::firstOrFail();
        $this->assertSame('ticket-2', $cursor->cursor_external_id);
        $this->assertSame('dressafford.com', Conversation::where('external_id', 'ticket-2')->firstOrFail()->site_key);
        Http::assertSent(function ($request): bool {
            $url = urldecode($request->url());

            return str_contains($url, 'sortBy=updatedAt')
                && str_contains($url, 'next.ID=ticket-1');
        });
    }

    public function test_incremental_sync_records_a_successful_empty_check(): void
    {
        $account = ChannelAccount::create([
            'channel' => 'helpdesk',
            'external_account_id' => '12345',
            'sync_status' => 'running',
        ]);
        ChannelSyncCursor::create([
            'channel_account_id' => $account->id,
            'source_resource' => 'tickets',
            'silo' => 'tickets',
            'status' => 'open',
            'sort_by' => 'updatedAt',
            'cursor_value' => '2026-09-01T10:00:00Z',
            'cursor_external_id' => 'ticket-1',
        ]);
        Http::fake(['*' => Http::response([], 200, ['X-Total-Results' => '0'])]);

        $this->artisan('helpdesk:sync-incremental', [
            '--silo' => ['tickets'],
            '--status' => ['open'],
        ])->assertSuccessful();

        $account->refresh();
        $this->assertSame('completed', $account->sync_status);
        $this->assertNotNull($account->last_synced_at);
        $this->assertNull($account->sync_error);
    }

    public function test_helpdesk_analysis_classifies_customer_messages_and_builds_reply_candidates(): void
    {
        $arguments = ['--fixture' => base_path('tests/fixtures/helpdesk-tickets.json')];
        $this->artisan('helpdesk:sync', $arguments)->assertSuccessful();
        $customerEvent = ConversationEvent::where('author_type', 'client')->firstOrFail();
        $customerEvent->update(['body_text' => 'Where is my package?']);

        $this->artisan('helpdesk:analyze')->assertSuccessful();
        $this->artisan('helpdesk:analyze')->assertSuccessful();

        $this->assertDatabaseCount('conversation_event_analyses', 1);
        $this->assertDatabaseCount('conversation_reply_candidates', 1);
        $this->assertDatabaseCount('conversation_analyses', 1);
        $this->assertDatabaseHas('conversation_labels', [
            'label_type' => 'category',
            'label' => 'logistics',
        ]);
        $this->assertSame('logistics', ConversationEventAnalysis::firstOrFail()->category);
        $this->assertSame('logistics', ConversationReplyCandidate::firstOrFail()->category);
        $this->assertSame('logistics', ConversationAnalysis::firstOrFail()->primary_category);
    }
}
