<?php

namespace App\Console\Commands;

use App\Models\CommerceSite;
use App\Services\ProductQuestions\ProductQuestionFactoryCapabilityAggregator;
use Illuminate\Console\Command;

class AggregateProductQuestionFactoryCapabilities extends Command
{
    protected $signature = 'product-questions:aggregate-factory-capabilities {--site=dorriswedding} {--days=150}';

    protected $description = 'Aggregate classified factory-review questions into reusable capability cards';

    public function handle(ProductQuestionFactoryCapabilityAggregator $aggregator): int
    {
        $site = CommerceSite::where('enabled', true)->where('site_key', trim((string) $this->option('site')))->first();
        $days = (int) $this->option('days');
        if ($site === null || $days < 1 || $days > 3650) {
            $this->error('请提供有效的 --site 和 --days。');

            return self::INVALID;
        }

        $result = $aggregator->aggregate($site, $days);
        $this->info("已生成 {$result['capabilities']} 张工厂能力卡，覆盖 {$result['questions']} 条问答。");

        return self::SUCCESS;
    }
}
