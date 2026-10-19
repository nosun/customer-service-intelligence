<?php

namespace App\Console\Commands;

use App\Services\TicketWork\TicketDraftReleaseGate;
use App\Services\TicketWork\TicketDraftReplaySuite;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class GateTicketDraftRelease extends Command
{
    protected $signature = 'tickets:gate-draft-release
                            {predictions : JSON predictions produced by the real n8n replay runner}
                            {--workflow-key=work/ticket-draft-v1}
                            {--workflow-version=1.0.0}
                            {--workflow-file=n8n/workflows/work__ticket-draft-v1.json}
                            {--suite=resources/data/ticket-draft-replay-v1.json}
                            {--annotations= : Human fact and Policy review JSON keyed by replay case ID}
                            {--report= : Output release report path}';

    protected $description = 'Apply the Ticket Draft release gate to real n8n replay predictions';

    public function handle(TicketDraftReplaySuite $suite, TicketDraftReleaseGate $gate): int
    {
        $predictionPath = $this->path((string) $this->argument('predictions'));
        $suitePath = $this->path((string) $this->option('suite'));
        $workflowPath = $this->path((string) $this->option('workflow-file'));
        $predictions = $this->records($this->json($predictionPath));
        if ($annotations = trim((string) $this->option('annotations'))) {
            $annotations = collect($this->records($this->json($this->path($annotations))))->keyBy('id');
            $predictions = collect($predictions)->map(fn (array $prediction): array => [
                ...$prediction,
                ...array_intersect_key((array) $annotations->get($prediction['id'] ?? null, []), array_flip([
                    'fact_error', 'fact_errors', 'policy_violation', 'policy_violations', 'accepted_as_is', 'review_notes',
                ])),
            ])->values()->all();
        }
        $metrics = $suite->evaluate($predictions, $suitePath);
        $result = $gate->evaluate($metrics);
        $report = [
            'schema_version' => TicketDraftReleaseGate::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'workflow' => [
                'key' => (string) $this->option('workflow-key'),
                'version' => (string) $this->option('workflow-version'),
                'definition_checksum' => hash_file('sha256', $workflowPath),
            ],
            'suite' => [
                'path' => Str::after($suitePath, base_path().DIRECTORY_SEPARATOR),
                'checksum' => hash_file('sha256', $suitePath),
                'size' => $metrics['suite_size'],
            ],
            'predictions' => [
                'path' => Str::after($predictionPath, base_path().DIRECTORY_SEPARATOR),
                'checksum' => hash_file('sha256', $predictionPath),
            ],
            'metrics' => $metrics,
            ...$result,
        ];
        $reportPath = $this->reportPath();
        $directory = dirname($reportPath);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create report directory: {$directory}");
        }
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
        chmod($reportPath, 0600);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->line('report='.$reportPath);

        return $result['passed'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<mixed> */
    private function json(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("JSON file does not exist: {$path}");
        }
        $value = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new RuntimeException("JSON file must contain an array: {$path}");
        }

        return $value;
    }

    /** @param array<mixed> $value @return list<array<string,mixed>> */
    private function records(array $value): array
    {
        return array_is_list($value) ? array_values($value) : collect($value)->map(fn ($record, $id): array => [
            'id' => (string) $id,
            ...((array) $record),
        ])->values()->all();
    }

    private function path(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    private function reportPath(): string
    {
        $path = trim((string) $this->option('report'));

        return $this->path($path === '' ? 'storage/app/ticket-draft-replays/release-report-'.now()->format('Ymd-His').'.json' : $path);
    }
}
