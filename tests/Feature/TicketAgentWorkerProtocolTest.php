<?php

namespace Tests\Feature;

use App\Services\Agents\TicketAgentWorker;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Tests\TestCase;
use Throwable;

class TicketAgentWorkerProtocolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'agent-investigator.node_binary' => base_path('tests/fixtures/agents/protocol-worker.mjs'),
            'ticket-agent.openai' => ['api_key' => 'fixture-secret', 'base_url' => 'https://unused.test', 'model' => 'fixture'],
        ]);
    }

    public function test_real_process_round_trip_handles_split_messages_and_checkpoints(): void
    {
        $calls = [];
        $checkpoints = [];
        $result = app(TicketAgentWorker::class)->run(
            $this->initial('valid'), 'openai',
            function ($name, $arguments) use (&$calls): array {
                $calls[] = [$name, $arguments];

                return ['checked' => true];
            },
            function ($checkpoint) use (&$checkpoints): void {
                $checkpoints[] = $checkpoint;
            },
        );
        $this->assertSame([['read_conversation', []]], $calls);
        $this->assertSame([['stage' => 'investigated']], $checkpoints);
        $this->assertSame('needs_review', $result['status']);
        $this->assertSame('openai', $result['provider']);
    }

    public function test_protocol_errors_and_process_exit_do_not_return_a_report_or_expose_diagnostics(): void
    {
        foreach (['exit', 'invalid_json', 'wrong_id', 'wrong_hash', 'trailing'] as $mode) {
            $error = null;
            try {
                app(TicketAgentWorker::class)->run($this->initial($mode), 'openai', fn () => ['checked' => true]);
            } catch (Throwable $exception) {
                $error = $exception;
            }
            $this->assertNotNull($error, $mode.' must fail');
            $this->assertStringNotContainsString('SECRET', $error->getMessage());
            if ($mode === 'wrong_id') {
                $this->assertInstanceOf(RuntimeException::class, $error);
            }
        }
    }

    public function test_unresponsive_process_times_out(): void
    {
        config(['ticket-agent.timeout' => 1]);
        $this->expectException(ProcessTimedOutException::class);
        app(TicketAgentWorker::class)->run($this->initial('timeout'), 'openai', fn () => []);
    }

    private function initial(string $mode): array
    {
        return ['case_key' => 'ticket:fixture', 'input_hash' => 'fixture-hash', 'fixture_mode' => $mode];
    }
}
