<?php

namespace Tests\Feature;

use App\Services\HelpDesk\HelpDeskClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class HelpDeskClientTest extends TestCase
{
    public function test_it_fetches_ticket_pages_with_a_stable_cursor(): void
    {
        Http::fakeSequence()
            ->push([
                ['ID' => 'ticket-1', 'createdAt' => '2026-01-01T00:00:00Z'],
                ['ID' => 'ticket-2', 'createdAt' => '2026-01-02T00:00:00Z'],
            ], 200, ['X-Total-Results' => '3'])
            ->push([
                ['ID' => 'ticket-3', 'createdAt' => '2026-01-03T00:00:00Z'],
            ]);

        $result = (new HelpDeskClient('12345', 'secret', 'https://api.helpdesk.test'))
            ->listTickets(limit: 3, pageSize: 2);

        $this->assertSame(['ticket-1', 'ticket-2', 'ticket-3'], array_column($result['tickets'], 'ID'));
        $this->assertSame(2, $result['requests']);
        $this->assertSame(3, $result['total_results']);

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            $url = urldecode($request->url());

            return str_contains($url, 'next.value=2026-01-02T00:00:00Z')
                && str_contains($url, 'next.ID=ticket-2')
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('12345:secret'));
        });
    }

    public function test_it_rejects_missing_credentials_before_sending_a_request(): void
    {
        Http::fake();

        try {
            (new HelpDeskClient('', '', 'https://api.helpdesk.test'))->listTickets();
            $this->fail('Expected missing HelpDesk credentials to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HelpDesk credentials are missing', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_it_can_resume_pages_sorted_by_updated_time(): void
    {
        Http::fake([
            '*' => Http::response([
                ['ID' => 'ticket-2', 'updatedAt' => '2026-01-02T00:00:00Z'],
            ], 200, ['X-Total-Results' => '1']),
        ]);

        $pages = iterator_to_array((new HelpDeskClient('12345', 'secret', 'https://api.helpdesk.test'))
            ->ticketPages(
                limit: 100,
                pageSize: 100,
                sortBy: 'updatedAt',
                initialCursor: ['value' => '2026-01-01T00:00:00Z', 'ID' => 'ticket-1'],
            ));

        $this->assertSame('ticket-2', $pages[0]['tickets'][0]['ID']);
        Http::assertSent(function ($request): bool {
            $url = urldecode($request->url());

            return str_contains($url, 'sortBy=updatedAt')
                && str_contains($url, 'next.value=2026-01-01T00:00:00Z')
                && str_contains($url, 'next.ID=ticket-1');
        });
    }
}
