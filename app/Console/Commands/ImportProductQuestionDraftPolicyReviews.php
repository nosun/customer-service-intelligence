<?php

namespace App\Console\Commands;

use App\Models\CommerceSite;
use App\Services\ProductQuestions\ProductQuestionDraftPolicyReviewImporter;
use Illuminate\Console\Command;
use RuntimeException;

class ImportProductQuestionDraftPolicyReviews extends Command
{
    protected $signature = 'product-questions:import-draft-policy-reviews
                            {--site= : Enabled commerce site key}
                            {--workflow-version=1.2.0}
                            {--predictions=storage/app/product-question-replays/1.2.0-predictions.json}';

    protected $description = 'One-time/manual migration of replay policy gaps into backend review candidates';

    public function handle(ProductQuestionDraftPolicyReviewImporter $importer): int
    {
        $siteKey = trim((string) $this->option('site'));
        $site = CommerceSite::query()->where('enabled', true)->where('site_key', $siteKey)->first();
        if ($site === null) {
            $this->error("Enabled commerce site not found: {$siteKey}");

            return self::INVALID;
        }
        $path = (string) $this->option('predictions');
        $path = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! is_file($path)) {
            $this->error("Prediction file not found: {$path}");

            return self::INVALID;
        }

        try {
            $predictions = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($predictions)) {
                throw new RuntimeException('Prediction file must contain a JSON array.');
            }
            $run = $importer->import($site, $predictions, trim((string) $this->option('workflow-version')));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Imported {$run->candidate_count} policy review candidates into batch {$run->batch_key}.");

        return self::SUCCESS;
    }
}
