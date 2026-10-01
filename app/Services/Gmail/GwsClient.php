<?php

namespace App\Services\Gmail;

use Closure;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

class GwsClient
{
    private readonly ?Closure $progress;

    public function __construct(
        private readonly ?string $configDir = null,
        ?callable $progress = null,
        private readonly int $maxAttempts = 3,
        private readonly int $retryDelayMilliseconds = 2000,
    ) {
        $this->progress = $progress === null ? null : Closure::fromCallable($progress);
    }

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
        foreach ($this->decodePages($output) as $page) {
            foreach ($page['threads'] ?? [] as $thread) {
                if (isset($thread['id'])) {
                    $ids[(string) $thread['id']] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /** @return list<array<string, mixed>> */
    private function decodePages(string $output): array
    {
        $output = trim($output);
        if ($output === '') {
            return [];
        }

        try {
            $page = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

            return is_array($page) ? [$page] : [];
        } catch (JsonException) {
            // Multiple pages are emitted as newline-delimited JSON by gws.
        }

        $pages = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $page = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($page)) {
                throw new RuntimeException('gws returned an invalid Gmail page.');
            }
            $pages[] = $page;
        }

        return $pages;
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
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $process = Process::timeout(120);
            if ($this->configDir !== null && $this->configDir !== '') {
                $process = $process->env(['GOOGLE_WORKSPACE_CLI_CONFIG_DIR' => $this->configDir]);
            }

            $result = $process->run(array_merge([
                (string) config('services.gmail.gws_binary', 'gws'),
            ], $arguments));

            if ($result->successful()) {
                return $result->output();
            }

            if ($attempt < $this->maxAttempts && ! in_array($result->exitCode(), [2, 3], true)) {
                $delay = $this->retryDelayMilliseconds * $attempt;
                $this->progress?->__invoke("Gmail 请求失败，{$delay} 毫秒后进行第 ".($attempt + 1).' 次尝试');
                usleep($delay * 1000);

                continue;
            }

            throw new RuntimeException($this->failureMessage(
                $result->output(),
                $result->errorOutput(),
                $result->exitCode(),
            ));
        }

        throw new RuntimeException('gws request failed.');
    }

    private function failureMessage(string $output, string $errorOutput, int $exitCode): string
    {
        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $apiMessage = $decoded['error']['message'] ?? null;
        } catch (JsonException) {
            $apiMessage = null;
        }

        return trim(implode("\n", array_filter([
            trim($errorOutput),
            is_string($apiMessage) ? trim($apiMessage) : null,
        ]))) ?: "gws exited with code {$exitCode}";
    }
}
