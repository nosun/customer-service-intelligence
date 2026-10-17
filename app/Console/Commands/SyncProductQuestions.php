<?php

namespace App\Console\Commands;

use App\Models\CommerceSite;
use App\Services\TradeManager\ProductQuestionSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncProductQuestions extends Command
{
    protected $signature = 'product-questions:sync
                            {--site= : Site key or numeric ID; defaults to every enabled site}
                            {--per-page=100 : API page size, 1 to 100}
                            {--full : Force a full snapshot and reconcile remote deletions}';

    protected $description = 'Synchronize product questions from each configured tradeManager Admin API';

    public function handle(ProductQuestionSyncService $sync): int
    {
        $perPage = (int) $this->option('per-page');
        if ($perPage < 1 || $perPage > 100) {
            $this->error('--per-page must be between 1 and 100.');

            return self::INVALID;
        }

        $query = CommerceSite::query()->where('enabled', true)->orderBy('id');
        $selected = trim((string) $this->option('site'));
        if ($selected !== '') {
            $query->where(function ($query) use ($selected): void {
                $query->where('site_key', $selected);
                if (ctype_digit($selected)) {
                    $query->orWhere('id', (int) $selected);
                }
            });
        }
        $sites = $query->get();
        if ($sites->isEmpty()) {
            $this->error($selected === '' ? '没有已启用的站点。' : "未找到已启用站点：{$selected}");

            return self::FAILURE;
        }

        $failed = false;
        foreach ($sites as $site) {
            $this->newLine();
            $this->info("同步 {$site->name} ({$site->site_key})");
            try {
                $result = $sync->sync(
                    $site,
                    $perPage,
                    fn (int $page, int $lastPage, int $seen) => $this->line("  page {$page}/{$lastPage}, seen={$seen}"),
                    (bool) $this->option('full'),
                );
                $this->info(
                    "完成：mode={$result['mode']} pages={$result['pages']} total={$result['reported_total']} "
                    ."saved={$result['records_saved']} missing={$result['missing_marked']}",
                );
            } catch (Throwable $exception) {
                report($exception);
                $this->error('失败：'.$exception->getMessage());
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
