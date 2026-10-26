<?php

namespace Tests\Feature;

use App\Services\Agents\TicketAgentDocuments;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TicketAgentDocumentsTest extends TestCase
{
    public function test_arbitrary_urls_local_paths_credentials_ports_and_unapproved_hosts_are_never_fetched(): void
    {
        Http::preventStrayRequests();
        foreach (['http://127.0.0.1/secret', 'file:///etc/passwd', 'https://evil.test/a.pdf',
            'https://cdn.livechat-files.com.evil.test/dal/api/file/helpdesk/att/a',
            'https://user:pass@cdn.livechat-files.com/dal/api/file/helpdesk/att/a',
            'https://cdn.livechat-files.com:8443/dal/api/file/helpdesk/att/a',
            'https://cdn.livechat-files.com/private'] as $url) {
            $result = app(TicketAgentDocuments::class)->inspect(['url' => $url]);
            $this->assertSame('unsupported_source', $result['status']);
        }
        Http::assertNothingSent();
    }

    public function test_document_login_pages_redirects_and_failed_requests_are_explicit_gaps(): void
    {
        foreach ([Http::response('<html><title>Google Drive: Sign-in</title></html>', 200, ['Content-Type' => 'text/html']),
            Http::response('', 302, ['Location' => 'http://127.0.0.1/secret']), Http::response('Not found', 404)] as $index => $response) {
            Http::fake(['https://drive.google.com/uc?export=download&id=test-id-'.$index => $response]);
            $result = app(TicketAgentDocuments::class)->inspect(['url' => 'https://drive.google.com/file/d/test-id-'.$index.'/view']);
            $this->assertSame($index === 2 ? 'unavailable' : 'access_required', $result['status']);
        }
    }

    public function test_file_size_hash_and_format_checks_prevent_unverified_document_extraction(): void
    {
        $url = 'https://cdn.livechat-files.com/dal/api/file/helpdesk/att/test.pdf';
        Http::fake([$url => Http::response('%PDF-1.4'.str_repeat('x', 100))]);
        $result = app(TicketAgentDocuments::class)->inspect(['url' => $url, 'sha256' => str_repeat('0', 64)]);
        $this->assertSame('unavailable', $result['status']);
        config(['ticket-agent.max_document_bytes' => 10]);
        $this->assertSame('unavailable', app(TicketAgentDocuments::class)->inspect(['url' => $url])['status']);
        config(['ticket-agent.max_document_bytes' => 1024]);
        $otherUrl = 'https://cdn.livechat-files.com/dal/api/file/helpdesk/att/test.txt';
        Http::fake([$otherUrl => Http::response('Unsupported plain text')]);
        $this->assertSame('unsupported_format', app(TicketAgentDocuments::class)->inspect(['url' => $otherUrl])['status']);
    }
}
