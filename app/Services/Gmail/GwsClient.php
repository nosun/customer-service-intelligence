<?php

namespace App\Services\Gmail;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class GwsClient
{
    public function __construct(private readonly ?string $configDir = null) {}

    /** @return list<string> */
    public function listThreadIds(string $query, int $pageLimit): array
    {
        $output = $this->run([
            'gmail', 'users', 'threads', 'list',
            '--params', json_encode([
                'userId' => 'me',
                'q' => $query,
                'maxResults' => 100,
            ], JSON_THROW_ON_ERROR),
            '--page-all',
            '--page-limit', (string) $pageLimit,
        ]);

        $ids = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $page = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            foreach ($page['threads'] ?? [] as $thread) {
                if (isset($thread['id'])) {
                    $ids[(string) $thread['id']] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /** @return array<string, mixed> */
    public function getThread(string $threadId): array
    {
        return json_decode($this->run([
            'gmail', 'users', 'threads', 'get',
            '--params', json_encode([
                'userId' => 'me',
                'id' => $threadId,
                'format' => 'full',
            ], JSON_THROW_ON_ERROR),
        ]), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $arguments */
    private function run(array $arguments): string
    {
        $process = Process::timeout(120);
        if ($this->configDir !== null && $this->configDir !== '') {
            $process = $process->env(['GOOGLE_WORKSPACE_CLI_CONFIG_DIR' => $this->configDir]);
        }

        $result = $process->run(array_merge([
            (string) config('services.gmail.gws_binary', 'gws'),
        ], $arguments));

        if ($result->failed()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: 'gws exited with code '.$result->exitCode());
        }

        return $result->output();
    }
}
