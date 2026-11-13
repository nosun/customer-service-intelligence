<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Services\CustomerContacts\CommunicationReader;
use App\Services\CustomerContacts\GmailCommunicationSource;
use App\Services\CustomerContacts\MultiDressPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MultiDressScenario;
use Tests\TestCase;

class MultiDressHelpDeskEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cached_exact_order_reply_blocks_first_letter_even_when_gmail_has_no_messages(): void
    {
        Http::preventStrayRequests();
        $this->ticket('dad20990101-1');
        $this->gmail();
        $identity = ['site_key' => 'dressafford', 'order_number' => 'dad20990101-1', 'email' => 'customer@example.invalid'];
        $communication = app(CommunicationReader::class)->capture($identity, now()->toImmutable()->subDay());
        $this->assertTrue($communication['prior_contact']);
        $this->assertTrue($communication['reply_seen']);
        $this->assertFalse($communication['complete']);
        $this->assertFalse($communication['fresh']);
        $this->assertCount(2, $communication['evidence_refs']);
        $this->assertSame('helpdesk:ticket-dad20990101-1:1', $communication['evidence_refs'][1]);
        $snapshot = MultiDressScenario::snapshot();
        $snapshot['identity'] = ['console_key' => 'seawave', 'site_key' => 'dressafford', 'sid' => 35,
            'oid' => 900002, 'order_number' => 'dad20990101-1'];
        $snapshot['order']['production_started'] = null;
        $this->assertSame(['decision' => 'manual', 'reason' => 'human_contact_or_reply'],
            app(MultiDressPolicy::class)->assess($snapshot, $communication));
        Http::assertNothingSent();
    }

    public function test_messages_for_another_order_are_ambiguous_and_not_a_confirmed_reply(): void
    {
        $this->ticket('dad20990101-2');
        $this->gmail();
        $communication = app(CommunicationReader::class)->capture(['site_key' => 'dressafford',
            'order_number' => 'dad20990101-1', 'email' => 'customer@example.invalid'], now()->toImmutable()->subDay());
        $this->assertFalse($communication['reply_seen']);
        $this->assertFalse($communication['prior_contact']);
        $this->assertFalse($communication['identity_unique']);
        $this->assertFalse($communication['complete']);
    }

    private function gmail(): void
    {
        $this->mock(GmailCommunicationSource::class)->shouldReceive('capture')->once()
            ->andReturn(['complete' => true, 'messages' => [], 'human_takeover' => false]);
    }

    private function ticket(string $orderNumber): void
    {
        $account = ChannelAccount::create(['channel' => 'helpdesk', 'external_account_id' => 'fixture-account']);
        $ticket = Conversation::create(['channel_account_id' => $account->id, 'external_id' => 'ticket-'.$orderNumber,
            'site_key' => 'dressafford.com', 'requester_email' => 'customer@example.invalid',
            'subject' => 'Please confirm '.$orderNumber, 'silo' => 'tickets', 'status' => 'open', 'raw_payload' => []]);
        foreach ([['0', 'agent', 'Please confirm both dresses.', 'message'],
            ['1', 'client', 'Keep order of both dresses. Thank you!', 'message'], ['2', 'client', '', 'status']] as [$id, $author, $text, $type]) {
            ConversationEvent::create(['conversation_id' => $ticket->id, 'external_id' => $id, 'event_type' => $type,
                'author_type' => $author, 'body_text' => $text, 'is_private' => false, 'occurred_at' => now()->subHour(),
                'content_hash' => hash('sha256', $id.$text), 'raw_payload' => []]);
        }
    }
}
