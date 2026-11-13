<?php

namespace Tests\Feature;

use App\Services\CustomerContacts\GmailFirstRoundTransport;
use App\Services\CustomerContacts\MultiDressTemplate;
use App\Services\Gmail\GwsClient;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MultiDressScenario as Scenario;
use Tests\TestCase;

class MultiDressGmailTransportTest extends TestCase
{
    public function test_send_calls_gws_once_and_verifies_returned_sent_message(): void
    {
        Storage::fake('local');
        $mail = [...app(MultiDressTemplate::class)->render(Scenario::snapshot(), ['question_module' => 'purpose_and_keep_plan']),
            'to' => 'customer@example.invalid', 'from' => 'ellenbridals.com@gmail.com', 'site_key' => 'ellenbridals', 'marker' => 'fixture-marker'];
        $payload = ['mimeType' => 'text/plain', 'headers' => array_map(fn ($k, $v) => ['name' => $k, 'value' => $v],
            ['From', 'To', 'Subject', 'X-CSI-Round', 'X-CSI-Content-SHA256', 'Message-ID'],
            [$mail['from'], $mail['to'], $mail['subject'], $mail['marker'], $mail['content_hash'], '<actual@provider.invalid>']),
            'body' => ['data' => rtrim(strtr(base64_encode($mail['body']), '+/', '-_'), '=')]];
        Process::fake(['*' => Process::sequence()
            ->push(Process::result(output: json_encode(['emailAddress' => $mail['from']])))
            ->push(Process::result(output: '{"id":"gmail-1"}'))
            ->push(Process::result(output: json_encode(['id' => 'gmail-1', 'threadId' => 'thread-1', 'labelIds' => ['SENT'],
                'internalDate' => (string) now()->getTimestampMs(), 'payload' => $payload])))]);
        $result = app(GmailFirstRoundTransport::class)->send($mail);
        $this->assertSame('matched', $result['state']);
        $this->assertSame('<actual@provider.invalid>', $result['rfc822_id']);
        Process::assertRanTimes(fn ($p) => in_array('send', $p->command, true), 1);
    }

    public function test_account_mismatch_stops_before_gws_send(): void
    {
        Process::fake(['*' => Process::result(output: '{"emailAddress":"another@example.invalid"}')]);
        $this->expectException(\RuntimeException::class);
        try {
            app(GmailFirstRoundTransport::class)->verifyAccount('ellenbridals');
        } finally {
            Process::assertDidntRun(fn ($p) => in_array('send', $p->command, true));
        }
    }

    public function test_incomplete_gmail_search_never_returns_partial_no_reply_evidence(): void
    {
        Process::fake(['*' => Process::result(output: '{"messages":[{"id":"m1"}],"nextPageToken":"more"}')]);
        $this->expectException(\RuntimeException::class);
        (new GwsClient(maxAttempts: 1))->listMessageIds('in:anywhere', 1);
    }
}
