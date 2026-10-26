<?php

namespace App\Services\Agents;

use RuntimeException;
use Symfony\Component\Process\Process;

class TicketInvestigationWorker
{
    public function run(array $input, string $provider): array
    {
        if (! in_array($provider, ['minimax', 'omniroute'], true)) {
            throw new RuntimeException('Supported Agent providers: minimax, omniroute.');
        }
        $settings = config('services.'.$provider);
        if (blank($settings['api_key'] ?? null) || blank($settings['base_url'] ?? null) || blank($settings['model'] ?? null)) {
            throw new RuntimeException('The selected Agent provider is not configured.');
        }
        $process = new Process(
            [(string) config('agent-investigator.node_binary'), base_path('agents/ticket-investigator/src/cli.mjs')],
            base_path('agents/ticket-investigator'),
            [
                'AGENT_API_KEY' => $settings['api_key'],
                'AGENT_BASE_URL' => $settings['base_url'],
                'AGENT_MODEL' => $settings['model'],
                'AGENT_PROVIDER' => $provider,
                'OPENAI_AGENTS_DISABLE_TRACING' => '1',
            ],
            json_encode($input, JSON_THROW_ON_ERROR),
            (int) config('agent-investigator.timeout') + 10,
        );
        $process->run();
        if (! $process->isSuccessful()) {
            // Don't echo provider errors: they may include prompts, endpoints or credentials.
            throw new RuntimeException('Agent Worker failed. Check dependency installation, provider compatibility and runtime limits.');
        }
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if (($report['case_key'] ?? null) !== $input['case_key'] || ($report['input_hash'] ?? null) !== $input['input_hash']
            || ! in_array($report['status'] ?? null, ['needs_review', 'blocked'], true)) {
            throw new RuntimeException('Agent report does not match the investigation input contract.');
        }

        return ['provider' => $provider, ...$report];
    }
}
