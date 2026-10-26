<?php

namespace App\Services\Agents;

use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class TicketAgentWorker
{
    public function run(array $initial, string $provider, callable $invoke, ?callable $progress = null): array
    {
        if (! in_array($provider, ['openai', 'omniroute', 'minimax'], true)) {
            throw new RuntimeException('Unsupported Ticket Agent provider.');
        }
        $settings = config($provider === 'openai' ? 'ticket-agent.openai' : 'services.'.$provider);
        if (blank($settings['api_key'] ?? null) || blank($settings['base_url'] ?? null) || blank($settings['model'] ?? null)) {
            throw new RuntimeException('Ticket Agent model provider is not configured.');
        }
        $stream = new InputStream;
        $stream->write(json_encode($initial, JSON_THROW_ON_ERROR)."\n");
        $process = new Process(
            [(string) config('agent-investigator.node_binary'), base_path('agents/ticket-investigator/src/assistant-cli.mjs')],
            base_path('agents/ticket-investigator'),
            ['AGENT_API_KEY' => $settings['api_key'], 'AGENT_BASE_URL' => $settings['base_url'],
                'AGENT_MODEL' => $settings['model'], 'AGENT_PROVIDER' => $provider, 'OPENAI_AGENTS_DISABLE_TRACING' => '1'],
            $stream, (int) config('ticket-agent.timeout'),
        );
        $buffer = '';
        $report = null;
        $requests = 0;
        try {
            $process->run(function (string $type, string $data) use (&$buffer, &$report, &$requests, $stream, $invoke, $progress): void {
                if ($type !== Process::OUT) {
                    return; // Provider diagnostics are never echoed to the caller.
                }
                $buffer .= $data;
                if (strlen($buffer) > 2_000_000) {
                    throw new RuntimeException('Agent protocol output exceeded its limit.');
                }
                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);
                    $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    if (($message['type'] ?? '') === 'tool' && $report === null) {
                        if (++$requests > config('ticket-agent.max_tool_calls') || ($message['id'] ?? null) !== $requests
                            || ! is_string($message['name'] ?? null) || ! is_array($message['arguments'] ?? null)) {
                            throw new RuntimeException('Invalid Agent tool request.');
                        }
                        $result = $invoke($message['name'], $message['arguments']);
                        $stream->write(json_encode(['id' => $message['id'], 'result' => $result], JSON_THROW_ON_ERROR)."\n");
                    } elseif (($message['type'] ?? '') === 'checkpoint' && $report === null && is_array($message['checkpoint'] ?? null)) {
                        if ($progress !== null) {
                            $progress($message['checkpoint']);
                        }
                    } elseif (($message['type'] ?? '') === 'result' && $report === null && is_array($message['result'] ?? null)) {
                        $report = $message['result'];
                        $stream->close();
                    } else {
                        throw new RuntimeException('Unexpected Agent protocol message.');
                    }
                }
            });
        } finally {
            $stream->close();
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        if (! $process->isSuccessful() || ! is_array($report) || trim($buffer) !== ''
            || ($report['case_key'] ?? null) !== $initial['case_key'] || ($report['input_hash'] ?? null) !== $initial['input_hash']) {
            $diagnostic = json_decode(trim($process->getErrorOutput()), true);
            $category = is_array($diagnostic) && preg_match('/\A[A-Za-z_]{1,60}\z/', $diagnostic['category'] ?? '') ? $diagnostic['category'] : 'invalid_protocol';
            throw new RuntimeException('Ticket Agent failed or returned an invalid report ('.$category.').');
        }

        return ['provider' => $provider, ...$report];
    }
}
