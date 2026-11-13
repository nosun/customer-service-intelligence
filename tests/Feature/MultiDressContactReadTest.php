<?php

namespace Tests\Feature;

use App\Services\CustomerContacts\CommunicationReader;
use App\Services\CustomerContacts\ConsoleContactReader;
use App\Services\CustomerContacts\GmailCommunicationSource;
use App\Services\CustomerContacts\HelpDeskCommunicationSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\MultiDressScenario as Scenario;
use Tests\TestCase;

class MultiDressContactReadTest extends TestCase
{
    public function test_contact_pagination_is_complete_only_on_final_page(): void
    {
        config(['services.console_new.base_url' => 'https://console.example.invalid/api/admin', 'services.console_new.token' => 'fixture']);
        Http::preventStrayRequests();
        Http::fake(['https://console.example.invalid/api/admin/customer-contacts*' => Http::sequence()
            ->push(['success' => true, 'data' => ['items' => [], 'pagination' => ['current_page' => 1, 'last_page' => 2]]])
            ->push(['success' => true, 'data' => ['items' => [['id' => 301, 'source' => 'multi_dress']], 'pagination' => ['current_page' => 2, 'last_page' => 2]]])]);
        $reader = app(ConsoleContactReader::class);
        $this->assertFalse($reader->candidates(1, 100)['complete']);
        $this->assertTrue($reader->candidates(2, 100)['complete']);
        Http::assertSent(fn ($r) => ! isset($r['source']));
    }

    public function test_helpdesk_unknown_is_not_treated_as_no_reply(): void
    {
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        $this->mock(GmailCommunicationSource::class)->shouldReceive('capture')->once()->andReturn(['complete' => true, 'messages' => [], 'human_takeover' => false]);
        $this->mock(HelpDeskCommunicationSource::class)->shouldReceive('capture')->once()->andReturn(['complete' => false, 'messages' => [], 'human_takeover' => false]);
        $c = app(CommunicationReader::class)->capture([...Scenario::snapshot()['identity'], 'email' => 'customer@example.invalid'], now()->toImmutable());
        $this->assertFalse($c['complete']);
        $this->assertFalse($c['fresh']);
    }

    public function test_any_customer_message_stops_timeout_and_ambiguity_fails_closed(): void
    {
        foreach (['customer_reply', 'automatic', 'ambiguous'] as $kind) {
            $this->forgetMock(GmailCommunicationSource::class);
            $this->forgetMock(HelpDeskCommunicationSource::class);
            $this->mock(GmailCommunicationSource::class)->shouldReceive('capture')->andReturn(['complete' => true, 'human_takeover' => false,
                'messages' => [['id' => 'x', 'kind' => $kind]]]);
            $this->mock(HelpDeskCommunicationSource::class)->shouldReceive('capture')->andReturn(['complete' => true, 'human_takeover' => false, 'messages' => []]);
            $c = app(CommunicationReader::class)->capture([...Scenario::snapshot()['identity'], 'email' => 'customer@example.invalid'], now()->toImmutable());
            $this->assertTrue($kind === 'customer_reply' ? $c['reply_seen'] : ! $c['identity_unique']);
        }
    }
}
