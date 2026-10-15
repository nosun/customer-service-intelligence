<?php

namespace App\Console\Commands;

use App\Models\CommerceProduct;
use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Services\TradeManager\ProductCatalogSyncService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class SyncQuestionProducts extends Command
{
    protected $signature = 'product-questions:sync-products
        {--site= : Site key; required when more than one enabled site exists}
        {--since-days= : Only products referenced by questions created within this many days}
        {--limit= : Maximum distinct product SNs}
        {--work-queue= : Only products referenced by analyses in this work queue}
        {--shards=1 : Split distinct SNs into stable shards for parallel workers}
        {--shard=0 : Zero-based shard handled by this worker}
        {--stale-hours=24 : Skip products fetched within this many hours}
        {--force : Fetch even when the local snapshot is fresh}';

    protected $description = 'Synchronize product details, images and options for product question SNs';

    public function handle(ProductCatalogSyncService $sync): int
    {
        try {
            $options = validator([
                'since_days' => $this->option('since-days') === null ? null : (int) $this->option('since-days'),
                'limit' => $this->option('limit') === null ? null : (int) $this->option('limit'),
                'stale_hours' => (int) $this->option('stale-hours'),
                'work_queue' => trim((string) $this->option('work-queue')) ?: null,
                'shards' => (int) $this->option('shards'),
                'shard' => (int) $this->option('shard'),
            ], [
                'since_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
                'stale_hours' => ['required', 'integer', 'min:0', 'max:8760'],
                'work_queue' => ['nullable', 'string', 'max:50'],
                'shards' => ['required', 'integer', 'min:1', 'max:8'],
                'shard' => ['required', 'integer', 'min:0', 'max:7'],
            ])->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }
        if ($options['shard'] >= $options['shards']) {
            $this->error('--shard 必须小于 --shards。');

            return self::INVALID;
        }

        $site = $this->site();
        if ($site === null) {
            return self::INVALID;
        }
        $query = ProductQuestion::query()
            ->where('commerce_site_id', $site->id)
            ->where('remote_present', true)
            ->where('sn', '!=', '');
        if ($options['since_days'] !== null) {
            $query->where('remote_created_at', '>=', now()->subDays($options['since_days']));
        }
        if ($options['work_queue'] !== null) {
            $query->whereHas('analysis', fn ($analysis) => $analysis->where('work_queue', $options['work_queue']));
        }
        $sns = $query->distinct()->orderBy('sn')->pluck('sn');
        $sns = $sns->filter(fn (string $sn): bool => abs(crc32($sn)) % $options['shards'] === $options['shard'])->values();
        if ($options['limit'] !== null) {
            $sns = $sns->take($options['limit']);
        }

        $completed = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($sns as $index => $sn) {
            $existing = CommerceProduct::where('commerce_site_id', $site->id)->where('sn', $sn)->first();
            if (! $this->option('force') && $existing?->sync_status === 'completed' && $existing->synced_at?->gte(now()->subHours($options['stale_hours']))) {
                $skipped++;
                $this->line(sprintf('[%d/%d] %s | skipped_fresh', $index + 1, $sns->count(), $sn));

                continue;
            }
            $result = $sync->sync($site, $sn);
            $result['status'] === 'completed' ? $completed++ : $failed++;
            $this->line(sprintf(
                '[%d/%d] %s | %s%s',
                $index + 1,
                $sns->count(),
                $sn,
                $result['status'],
                $result['error'] ? ' | '.$result['error'] : '',
            ));
        }

        $this->line(json_encode(compact('completed', 'skipped', 'failed'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function site(): ?CommerceSite
    {
        $query = CommerceSite::query()->where('enabled', true);
        $key = trim((string) $this->option('site'));
        if ($key !== '') {
            $site = $query->where('site_key', $key)->first();
            if ($site === null) {
                $this->error("找不到已启用站点：{$key}");
            }

            return $site;
        }
        if ($query->count() !== 1) {
            $this->error('存在零个或多个已启用站点，请使用 --site 指定站点。');

            return null;
        }

        return $query->first();
    }
}
