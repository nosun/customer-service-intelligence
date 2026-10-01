<?php

namespace Tests\Feature;

use App\Services\Gmail\GwsClient;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class GwsClientTest extends TestCase
{
    public function test_it_parses_pretty_printed_single_page_json(): void
    {
        Process::fake([
            '*' => Process::result(output: json_encode([
                'threads' => [
                    ['id' => 'thread-1', 'snippet' => '订单进度咨询 👗'],
                    ['id' => 'thread-2'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
        ]);

        $this->assertSame(
            ['thread-1', 'thread-2'],
            (new GwsClient)->listThreadIds('newer_than:7d', 1),
        );
    }

    public function test_it_parses_multiple_ndjson_pages(): void
    {
        Process::fake([
            '*' => Process::result(output: implode("\n", [
                json_encode(['threads' => [['id' => 'thread-1']]], JSON_THROW_ON_ERROR),
                json_encode(['threads' => [['id' => 'thread-2']]], JSON_THROW_ON_ERROR),
            ])),
        ]);

        $this->assertSame(
            ['thread-1', 'thread-2'],
            (new GwsClient)->listThreadIds('newer_than:7d', 2),
        );
    }

    public function test_it_retries_a_transient_gws_failure(): void
    {
        $progress = [];
        Process::fake([
            '*' => Process::sequence()
                ->push(Process::result(errorOutput: 'HTTP request failed', exitCode: 1))
                ->push(Process::result(output: json_encode([
                    'threads' => [['id' => 'thread-after-retry']],
                ], JSON_THROW_ON_ERROR))),
        ]);

        $client = new GwsClient(
            progress: function (string $message) use (&$progress): void {
                $progress[] = $message;
            },
            retryDelayMilliseconds: 0,
        );

        $this->assertSame(['thread-after-retry'], $client->listThreadIds('newer_than:7d', 1));
        $this->assertCount(1, $progress);
        Process::assertRanTimes(fn (): bool => true, 2);
    }
}
