<?php

namespace App\Console\Commands;

use App\Services\ProductQuestions\ProductQuestionDraftReleaseGate;
use App\Services\ProductQuestions\ProductQuestionDraftReplaySuite;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class GateProductQuestionDraftRelease extends Command
{
    protected $signature = 'product-questions:gate-draft-release
                            {predictions : JSON predictions produced by the real n8n replay runner}
                            {--workflow-key=work/qa-draft-agent-v1}
                            {--workflow-version=1.1.0}
                            {--workflow-file=n8n/workflows/work__qa-draft-agent-v1.json}
                            {--suite=resources/data/product-question-draft-replay-v1.json}
                            {--annotations= : Human fact and policy review JSON keyed by replay case ID}
                            {--report= : Output release report path}';

    protected $description = 'Apply the product-question Draft release quality gate to real n8n replay predictions';

    public function handle(ProductQuestionDraftReplaySuite $suite, ProductQuestionDraftReleaseGate $gate): int
    {
        $predictionPath = $this->path((string) $this->argument('predictions'));
        $suitePath = $this->path((string) $this->option('suite'));
        $workflowPath = $this->path((string) $this->option('workflow-file'));
        $predictions = $this->records($this->json($predictionPath));
        if ($annotations = trim((string) $this->option('annotations'))) {
            $predictions = $this->mergeAnnotations(
                $predictions,
                $this->records($this->json($this->path($annotations))),
            );
        }

        $metrics = $suite->evaluate($predictions, $suitePath);
        $result = $gate->evaluate($metrics);
        $report = [
            'schema_version' => ProductQuestionDraftReleaseGate::SCHEMA_VERSION,
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
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->line('report='.$reportPath);
        if (! $result['passed']) {
            $this->error('Draft release gate failed: '.implode('; ', $result['failures']));

            return self::FAILURE;
        }
        $this->info('Draft release gate passed. This report may be used to activate the matching workflow definition.');

        return self::SUCCESS;
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

    /** @param array<mixed> $value
     * @return list<array<string,mixed>>
     */
    private function records(array $value): array
    {
        if (array_is_list($value)) {
            return array_values($value);
        }

        return collect($value)->map(function (mixed $record, string|int $id): array {
            if (! is_array($record)) {
                throw new RuntimeException('Prediction and annotation entries must be JSON objects.');
            }

            return ['id' => (string) $id, ...$record];
        })->values()->all();
    }

    /** @param list<array<string,mixed>> $predictions
     * @param  list<array<string,mixed>>  $annotations
     * @return list<array<string,mixed>>
     */
    private function mergeAnnotations(array $predictions, array $annotations): array
    {
        $annotations = collect($annotations)->keyBy('id');

        return collect($predictions)->map(function (array $prediction) use ($annotations): array {
            $annotation = $annotations->get($prediction['id'] ?? null, []);

            return [
                ...$prediction,
                ...array_intersect_key((array) $annotation, array_flip([
                    'fact_error', 'fact_errors', 'policy_violation', 'policy_violations', 'accepted_as_is', 'review_notes',
                ])),
            ];
        })->values()->all();
    }

    private function path(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    private function reportPath(): string
    {
        $path = trim((string) $this->option('report'));
        if ($path === '') {
            $path = 'storage/app/product-question-replays/release-report-'.now()->format('Ymd-His').'.json';
        }

        return $this->path($path);
    }
}
