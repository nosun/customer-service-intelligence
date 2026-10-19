<?php

namespace App\Console\Commands;

use App\Services\TicketWork\TicketDraftReplayRunner;
use App\Services\TicketWork\TicketDraftReplaySuite;
use Illuminate\Console\Command;
use RuntimeException;

class RunN8nTicketDraftReplay extends Command
{
    protected $signature = 'tickets:run-n8n-draft-replay
                            {--workflow-key=work/ticket-draft-v1}
                            {--workflow-version=1.0.0}
                            {--suite=resources/data/ticket-draft-replay-v1.json}
                            {--output= : Prediction JSON path}
                            {--review-template= : Human review annotation template path}
                            {--case=* : Optional replay case ID; repeat to run a focused subset}
                            {--limit=0 : Optional case limit for smoke testing}
                            {--batch-size=1 : Concurrent webhook batch size; keep 1 unless the model provider quota is known to allow more}
                            {--timeout=900 : Timeout per batch in seconds}
                            {--poll-ms=2000 : WorkItem status polling interval}
                            {--resume : Keep successful predictions already present in --output and rerun failed, timed-out, or missing cases}
                            {--keep : Keep isolated replay Tickets, Cases, WorkItems, and Drafts after the run}';

    protected $description = 'Run the fixed Ticket Draft suite through the real n8n webhook with frozen Fact fixtures';

    public function handle(TicketDraftReplaySuite $suite, TicketDraftReplayRunner $runner): int
    {
        $suitePath = $this->path((string) $this->option('suite'));
        $cases = $suite->cases($suitePath);
        $caseIds = array_values(array_unique(array_filter((array) $this->option('case'), 'is_string')));
        if ($caseIds !== []) {
            $cases = array_values(array_filter($cases, fn (array $case): bool => in_array($case['id'], $caseIds, true)));
            $missingCaseIds = array_diff($caseIds, array_column($cases, 'id'));
            if ($missingCaseIds !== []) {
                $this->error('Replay case IDs not found: '.implode(', ', $missingCaseIds));

                return self::INVALID;
            }
        }
        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $cases = array_slice($cases, 0, $limit);
        }
        $output = $this->outputPath();
        $directory = dirname($output);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create output directory: {$directory}");
        }

        $existing = (bool) $this->option('resume') && is_file($output)
            ? collect(json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR))->keyBy('id')
            : collect();
        $completed = $existing->reject(fn (array $prediction): bool => in_array(
            $prediction['status'] ?? null,
            ['failed', 'timed_out'],
            true,
        ));
        $cases = array_values(array_filter($cases, fn (array $case): bool => ! $completed->has($case['id'])));
        $write = function ($predictions) use ($output): void {
            file_put_contents(
                $output,
                json_encode(collect($predictions)->sortKeys()->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
                LOCK_EX,
            );
            chmod($output, 0600);
        };
        $current = $completed;
        $predictions = $runner->run(
            cases: $cases,
            workflowKey: (string) $this->option('workflow-key'),
            workflowVersion: (string) $this->option('workflow-version'),
            batchSize: max(1, min(20, (int) $this->option('batch-size'))),
            timeoutSeconds: max(1, (int) $this->option('timeout')),
            pollMilliseconds: max(10, (int) $this->option('poll-ms')),
            cleanup: ! (bool) $this->option('keep'),
            progress: fn (string $message) => $this->line($message),
            checkpoint: function (array $prediction) use (&$current, $write): void {
                $current->put($prediction['id'], $prediction);
                $write($current);
            },
        );
        $predictions = $current->merge(collect($predictions)->keyBy('id'))->sortKeys()->values()->all();
        $write($predictions);
        $reviewTemplate = $this->reviewTemplatePath($output);
        file_put_contents($reviewTemplate, json_encode(collect($predictions)->map(fn (array $prediction): array => [
            'id' => $prediction['id'],
            'fact_error' => null,
            'policy_violation' => null,
            'accepted_as_is' => null,
            'review_notes' => null,
        ])->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
        chmod($reviewTemplate, 0600);
        $this->info('predictions='.$output.' cases='.count($predictions));
        $this->info('review_template='.$reviewTemplate);
        $this->warn('Activation still requires human fact and Policy review for every Ticket replay case.');

        return collect($predictions)->contains(fn (array $prediction): bool => in_array($prediction['status'], ['failed', 'timed_out'], true))
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function path(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    private function outputPath(): string
    {
        $path = trim((string) $this->option('output'));
        if ($path === '') {
            $path = 'storage/app/ticket-draft-replays/n8n-predictions-'.now()->format('Ymd-His').'.json';
        }

        return $this->path($path);
    }

    private function reviewTemplatePath(string $output): string
    {
        $path = trim((string) $this->option('review-template'));

        return $path === '' ? preg_replace('/\.json$/', '', $output).'-review.json' : $this->path($path);
    }
}
