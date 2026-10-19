<?php

namespace App\Console\Commands;

use App\Models\CommerceProduct;
use App\Models\CommerceSite;
use App\Services\ProductQuestions\ProductQuestionDraftReplayRunner;
use App\Services\ProductQuestions\ProductQuestionDraftReplaySuite;
use Illuminate\Console\Command;
use RuntimeException;

class RunN8nProductQuestionDraftReplay extends Command
{
    protected $signature = 'product-questions:run-n8n-draft-replay
                            {--site= : Enabled commerce site key}
                            {--product-sn= : Synced product used as deterministic product context}
                            {--workflow-key=work/qa-draft-agent-v1}
                            {--workflow-version=1.1.0}
                            {--suite=resources/data/product-question-draft-replay-v1.json}
                            {--output= : Prediction JSON path}
                            {--review-template= : Human review annotation template path}
                            {--case=* : Optional replay case ID; repeat to run a focused subset}
                            {--limit=0 : Optional case limit for smoke testing}
                            {--batch-size=5 : Concurrent webhook batch size}
                            {--timeout=900 : Timeout per batch in seconds}
                            {--poll-ms=2000 : Draft status polling interval}
                            {--cleanup : Delete replay questions, requests and drafts after writing predictions}';

    protected $description = 'Run the fixed Draft replay suite through the real n8n webhook and save predictions';

    public function handle(ProductQuestionDraftReplaySuite $suite, ProductQuestionDraftReplayRunner $runner): int
    {
        $siteKey = trim((string) $this->option('site'));
        $productSn = trim((string) $this->option('product-sn'));
        if ($siteKey === '' || $productSn === '') {
            $this->error('--site and --product-sn are required.');

            return self::INVALID;
        }
        $site = CommerceSite::query()->where('enabled', true)->where('site_key', $siteKey)->first();
        if ($site === null) {
            $this->error("Enabled commerce site not found: {$siteKey}");

            return self::FAILURE;
        }
        $product = CommerceProduct::query()
            ->where('commerce_site_id', $site->id)
            ->where('sn', $productSn)
            ->first();
        if ($product === null) {
            $this->error("Synced product not found for {$siteKey}: {$productSn}");

            return self::FAILURE;
        }
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

        $predictions = $runner->run(
            site: $site,
            product: $product,
            cases: $cases,
            workflowKey: (string) $this->option('workflow-key'),
            workflowVersion: (string) $this->option('workflow-version'),
            batchSize: max(1, min(20, (int) $this->option('batch-size'))),
            timeoutSeconds: max(1, (int) $this->option('timeout')),
            pollMilliseconds: max(10, (int) $this->option('poll-ms')),
            cleanup: (bool) $this->option('cleanup'),
            progress: fn (string $message) => $this->line($message),
        );
        file_put_contents($output, json_encode($predictions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        $reviewTemplate = $this->reviewTemplatePath($output);
        file_put_contents($reviewTemplate, json_encode(collect($predictions)->map(fn (array $prediction): array => [
            'id' => $prediction['id'],
            'fact_error' => null,
            'policy_violation' => null,
            'accepted_as_is' => null,
            'relevance_ok' => null,
            'guidance_ok' => null,
            'tone_ok' => null,
            'review_notes' => null,
        ])->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        $this->info('predictions='.$output.' cases='.count($predictions));
        $this->info('review_template='.$reviewTemplate);
        $this->warn('Release activation still requires human fact_error and policy_violation annotations for every case.');

        return collect($predictions)->contains(fn (array $prediction): bool => in_array($prediction['status'], ['failed', 'stale', 'timed_out'], true))
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
            $path = 'storage/app/product-question-replays/n8n-predictions-'.now()->format('Ymd-His').'.json';
        }

        return $this->path($path);
    }

    private function reviewTemplatePath(string $output): string
    {
        $path = trim((string) $this->option('review-template'));
        if ($path !== '') {
            return $this->path($path);
        }

        return preg_replace('/\.json$/', '', $output).'-review.json';
    }
}
