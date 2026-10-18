<?php

namespace App\Console\Commands;

use App\Models\CommerceSite;
use App\Services\ProductQuestions\ProductQuestionFactoryCapabilityPolicyImporter;
use Illuminate\Console\Command;

class ImportProductQuestionFactoryCapabilityPolicies extends Command
{
    protected $signature = 'product-questions:import-factory-capability-policies {--site=dorriswedding}';

    protected $description = 'One-time/manual migration of aggregated factory evidence into policy review candidates';

    public function handle(ProductQuestionFactoryCapabilityPolicyImporter $importer): int
    {
        $site = CommerceSite::query()
            ->where('enabled', true)
            ->where('site_key', trim((string) $this->option('site')))
            ->first();
        if ($site === null) {
            $this->error('请提供有效的 --site。');

            return self::INVALID;
        }

        $run = $importer->import($site);
        $this->info("已导入 {$run->candidate_count} 个工厂能力 Policy 候选，批次 {$run->batch_key}。");

        return self::SUCCESS;
    }
}
