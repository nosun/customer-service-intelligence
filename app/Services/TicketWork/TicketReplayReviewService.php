<?php

namespace App\Services\TicketWork;

use App\Models\AiWorkflow;
use App\Models\AiWorkflowReplayCase;
use App\Models\AiWorkflowReplayRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TicketReplayReviewService
{
    public function __construct(
        private readonly TicketDraftReplaySuite $suite,
        private readonly TicketDraftReleaseGate $gate,
    ) {}

    public function import(
        string $workflowKey,
        string $workflowVersion,
        string $predictionsPath,
        ?string $annotationsPath = null,
        ?string $suitePath = null,
        ?int $importedBy = null,
    ): AiWorkflowReplayRun {
        $workflow = AiWorkflow::query()->where('workflow_key', $workflowKey)
            ->where('workflow_version', $workflowVersion)->firstOrFail();
        if ($workflow->domain !== 'tickets') {
            throw new RuntimeException('The selected workflow is not a Ticket workflow.');
        }
        $predictionsPath = $this->file($predictionsPath);
        $suitePath = $this->file($suitePath ?? resource_path('data/ticket-draft-replay-v1.json'));
        $predictions = collect($this->records($this->json($predictionsPath)))->keyBy('id');
        $annotations = $annotationsPath === null
            ? collect()
            : collect($this->records($this->json($this->file($annotationsPath))))->keyBy('id');
        $cases = $this->suite->cases($suitePath);
        if ($predictions->count() !== count($cases)) {
            throw new RuntimeException('Replay import requires one prediction for every Ticket suite case.');
        }

        return DB::transaction(function () use ($workflow, $predictionsPath, $suitePath, $predictions, $annotations, $cases, $importedBy): AiWorkflowReplayRun {
            $run = AiWorkflowReplayRun::query()->firstOrCreate([
                'ai_workflow_id' => $workflow->id,
                'suite_checksum' => hash_file('sha256', $suitePath),
                'predictions_checksum' => hash_file('sha256', $predictionsPath),
            ], [
                'status' => 'reviewing',
                'definition_checksum' => (string) $workflow->definition_checksum,
                'suite_path' => $this->relativePath($suitePath),
                'predictions_path' => $this->relativePath($predictionsPath),
                'case_count' => count($cases),
                'imported_by' => $importedBy,
                'imported_at' => now(),
            ]);
            $run->update([
                'status' => 'reviewing',
                'definition_checksum' => (string) $workflow->definition_checksum,
                'suite_path' => $this->relativePath($suitePath),
                'predictions_path' => $this->relativePath($predictionsPath),
                'case_count' => count($cases),
                'metrics' => null,
                'release_report' => null,
                'release_report_path' => null,
                'imported_by' => $importedBy,
                'imported_at' => now(),
                'gated_at' => null,
            ]);

            foreach ($cases as $case) {
                $prediction = $predictions->get($case['id']);
                if (! is_array($prediction)) {
                    throw new RuntimeException("Replay prediction is missing for {$case['id']}.");
                }
                $annotation = (array) $annotations->get($case['id'], []);
                $factErrors = $this->nullableCount($annotation['fact_errors'] ?? $annotation['fact_error'] ?? null);
                $policyViolations = $this->nullableCount($annotation['policy_violations'] ?? $annotation['policy_violation'] ?? null);
                $reviewed = $factErrors !== null && $policyViolations !== null;
                AiWorkflowReplayCase::query()->updateOrCreate(
                    ['ai_workflow_replay_run_id' => $run->id, 'case_key' => $case['id']],
                    [
                        'category' => $case['category'],
                        'question' => $case['question'],
                        'expected_intents' => $case['expected_intents'],
                        'must_block' => (bool) $case['must_block'],
                        'prediction' => $prediction,
                        'fact_errors' => $factErrors,
                        'policy_violations' => $policyViolations,
                        'accepted_as_is' => array_key_exists('accepted_as_is', $annotation)
                            ? $this->nullableBool($annotation['accepted_as_is']) : null,
                        'review_notes' => $annotation['review_notes'] ?? null,
                        'reviewed_by' => $reviewed ? $importedBy : null,
                        'reviewed_at' => $reviewed ? now() : null,
                    ],
                );
            }

            return $this->refreshProgress($run);
        });
    }

    public function refreshProgress(AiWorkflowReplayRun $run): AiWorkflowReplayRun
    {
        $reviewed = $run->cases()->whereNotNull('fact_errors')->whereNotNull('policy_violations')->count();
        $run->update(['reviewed_count' => $reviewed, 'status' => $reviewed === $run->case_count ? 'ready' : 'reviewing']);

        return $run->refresh();
    }

    /** @return array<string,mixed> */
    public function gate(AiWorkflowReplayRun $run, ?string $reportPath = null): array
    {
        $run->loadMissing('workflow', 'cases');
        if (! hash_equals((string) $run->workflow->definition_checksum, $run->definition_checksum)) {
            throw new RuntimeException('Workflow definition changed after this replay was imported.');
        }
        $predictions = $run->cases->map(fn (AiWorkflowReplayCase $case): array => [
            ...$case->prediction,
            'id' => $case->case_key,
            'fact_errors' => $case->fact_errors,
            'policy_violations' => $case->policy_violations,
            'accepted_as_is' => $case->accepted_as_is,
            'review_notes' => $case->review_notes,
        ])->values()->all();
        $metrics = $this->suite->evaluate($predictions, $this->absolutePath($run->suite_path));
        $result = $this->gate->evaluate($metrics);
        $report = [
            'schema_version' => TicketDraftReleaseGate::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'workflow' => [
                'key' => $run->workflow->workflow_key,
                'version' => $run->workflow->workflow_version,
                'definition_checksum' => $run->definition_checksum,
            ],
            'suite' => ['path' => $run->suite_path, 'checksum' => $run->suite_checksum, 'size' => $metrics['suite_size']],
            'predictions' => ['path' => $run->predictions_path, 'checksum' => $run->predictions_checksum],
            'review_run_id' => $run->id,
            'metrics' => $metrics,
            ...$result,
        ];
        $reportPath ??= storage_path("app/ticket-draft-replays/{$run->workflow->workflow_version}-release-report.json");
        $directory = dirname($reportPath);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create report directory: {$directory}");
        }
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
        chmod($reportPath, 0600);
        $run->update([
            'status' => $result['passed'] ? 'passed' : 'failed',
            'metrics' => $metrics,
            'release_report' => $report,
            'release_report_path' => $this->relativePath($reportPath),
            'gated_at' => now(),
        ]);
        $run->workflow->update(['replay_metrics' => $metrics]);

        return $report;
    }

    public function invalidateGate(AiWorkflowReplayRun $run): void
    {
        $run->update(['metrics' => null, 'release_report' => null, 'release_report_path' => null, 'gated_at' => null]);
    }

    /** @return array<mixed> */
    private function json(string $path): array
    {
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

    private function file(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved)) {
            throw new RuntimeException("Replay file does not exist: {$path}");
        }

        return $resolved;
    }

    private function relativePath(string $path): string
    {
        return Str::after($path, base_path().DIRECTORY_SEPARATOR);
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    private function nullableCount(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function nullableBool(mixed $value): ?bool
    {
        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }
}
