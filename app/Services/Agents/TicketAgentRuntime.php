<?php

namespace App\Services\Agents;

use RuntimeException;

class TicketAgentRuntime
{
    public static function capture(string $provider): array
    {
        if (! in_array($provider, ['openai', 'omniroute', 'minimax'], true)) {
            throw new RuntimeException('Unsupported Ticket Agent provider.');
        }
        $settings = config($provider === 'openai' ? 'ticket-agent.openai' : 'services.'.$provider, []);
        $sources = [];
        foreach (['src/assistant.mjs', 'src/assistant-cli.mjs', 'package-lock.json'] as $path) {
            $sources[$path] = hash_file('sha256', base_path('agents/ticket-investigator/'.$path));
        }

        return [
            'workflow_key' => TicketAgentWorkflow::KEY,
            'workflow_version' => TicketAgentWorkflow::VERSION,
            'provider' => $provider,
            'model' => $settings['model'] ?? null,
            'endpoint_hash' => hash('sha256', (string) ($settings['base_url'] ?? '')),
            'sources' => $sources,
        ];
    }
}
