<?php

namespace Tests\Feature;

use App\Services\ConsoleNew\ConsoleNewApiException;
use App\Services\ConsoleNew\ConsoleNewConnections;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MultiDressConsoleConnectionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.console_new.base_url' => 'https://seawave.test/api/admin', 'services.console_new.token' => 'seawave-secret',
            'console.connections.mydorris' => ['base_url' => 'https://mydorris.test/api/admin', 'token' => 'mydorris-secret', 'timeout' => 15]]);
        Http::preventStrayRequests();
    }

    public function test_interleaved_sites_keep_their_own_host_and_credentials(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['order_no' => 'sample']])]);
        $consoles = app(ConsoleNewConnections::class);
        foreach ([['dorriswedding', 'drw20261112-10001', 'mydorris'], ['dressafford.com', 'dad20261112-10001', 'seawave'],
            ['https://www.dorrisbridal.com/', 'drb20261112-10001', 'mydorris'], ['ellenbridals', 'ebd20261112-10001', 'seawave']] as [$site, $number, $key]) {
            $client = $consoles->forContext($site, $number);
            $this->assertSame($key, $client->source()['connection']);
            $client->findOrder($number);
            Http::assertSent(fn (Request $r) => $r->url() === "https://{$key}.test/api/admin/orders/{$number}"
                && $r->hasHeader('Authorization', 'Bearer '.$key.'-secret'));
        }
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'seawave.test') && $r->hasHeader('Authorization', 'Bearer mydorris-secret'));
    }

    public function test_conflicting_site_and_order_are_rejected_without_http(): void
    {
        Http::fake();
        try {
            app(ConsoleNewConnections::class)->forContext('dorriswedding.com', 'dad20261112-10001');
            $this->fail('Expected a routing conflict.');
        } catch (ConsoleNewApiException $e) {
            $this->assertSame('CONNECTION_MISMATCH', $e->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_new_terminal_rejects_writes_even_if_the_old_action_switch_is_on(): void
    {
        config(['services.console_new.ticket_actions_enabled' => true]);
        Http::fake();
        try {
            app(ConsoleNewConnections::class)->connection('mydorris')->updateOrder('drw20261112-10001', ['changes' => ['order_status' => -1]]);
            $this->fail('Expected read-only enforcement.');
        } catch (ConsoleNewApiException $e) {
            $this->assertSame('READ_ONLY_CONNECTION', $e->errorCode);
        }
        Http::assertNothingSent();
    }

    public function test_mydorris_rejects_contact_note_writes_before_any_request(): void
    {
        Http::fake();
        try {
            app(ConsoleNewConnections::class)->connection('mydorris')->updateContactNote(1, []);
            $this->fail('Expected read-only enforcement.');
        } catch (ConsoleNewApiException $e) {
            $this->assertSame('READ_ONLY_CONNECTION', $e->errorCode);
        }
        Http::assertNothingSent();
    }
}
