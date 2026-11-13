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
    public function test_dressafford_snapshot_uses_verified_seawave_site_35(): void
    {
        config(['services.console_new.base_url' => 'https://console.example.invalid/api/admin', 'services.console_new.token' => 'fixture']);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/admin/orders/dad20990101-1') {
                return Http::response(['success' => true, 'data' => ['id' => 900002, 'order_no' => 'dad20990101-1',
                    'site' => ['id' => 35, 'name' => 'dressafford.com', 'url' => 'https://www.dressafford.com'],
                    'customer' => ['email' => 'customer@example.invalid'], 'status' => ['payment_status' => 1, 'order_status' => 0, 'shipping_status' => 0],
                    'items' => [], 'notes' => []]]);
            }
            $items = [];
            if ($path === '/api/admin/customer-contacts') {
                $this->assertSame(35, (int) $request['sid']);
                $this->assertSame('dad20990101-1', $request['number']);
                $items = [['id' => 401, 'oid' => 900002, 'source' => 'multi_dress', 'status' => 'customer', 'version' => 1,
                    'blocks_assignment' => true, 'released_at' => null, 'deleted_at' => null, 'internal_note' => '', 'customer_requirement' => '']];
            } else {
                $this->assertContains($path, ['/api/admin/customer-contacts/401/histories', '/api/admin/work-orders']);
            }

            return Http::response(['success' => true, 'data' => ['items' => $items, 'pagination' => ['current_page' => 1, 'last_page' => 1]]]);
        });
        $snapshot = app(ConsoleContactReader::class)->snapshot('dad20990101-1');
        $this->assertSame('dressafford', $snapshot['identity']['site_key']);
        $this->assertSame(35, $snapshot['identity']['sid']);
        $this->assertTrue($snapshot['order']['on_hold']);
        $this->assertFalse($snapshot['console_prior_contact']);
        Http::assertSentCount(4);
    }

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
