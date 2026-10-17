<?php

namespace Tests\Feature;

use App\Models\CommerceProduct;
use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Services\TradeManager\ProductCatalogSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncQuestionProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_syncs_only_products_without_a_completed_snapshot(): void
    {
        $site = $this->site();
        $this->question($site, 1, 'MISSING');
        $this->question($site, 2, 'FAILED');
        $this->question($site, 3, 'FRESH');
        $this->product($site, 'FAILED', 'failed', now()->subHour());
        $this->product($site, 'FRESH', 'completed', now()->subDays(2));

        $synced = $this->fakeSync($site);

        $this->artisan('product-questions:sync-products', ['--site' => 'dressafford'])
            ->assertSuccessful();

        $this->assertSame(['FAILED', 'MISSING'], $synced());
    }

    public function test_refresh_stale_adds_expired_completed_snapshots(): void
    {
        $site = $this->site();
        $this->question($site, 1, 'MISSING');
        $this->question($site, 2, 'STALE');
        $this->question($site, 3, 'FRESH');
        $this->product($site, 'STALE', 'completed', now()->subHours(25));
        $this->product($site, 'FRESH', 'completed', now()->subHours(23));

        $synced = $this->fakeSync($site);

        $this->artisan('product-questions:sync-products', [
            '--site' => 'dressafford',
            '--refresh-stale' => true,
            '--stale-hours' => 24,
        ])->assertSuccessful();

        $this->assertSame(['MISSING', 'STALE'], $synced());
    }

    public function test_force_syncs_every_referenced_product(): void
    {
        $site = $this->site();
        $this->question($site, 1, 'MISSING');
        $this->question($site, 2, 'FRESH');
        $this->product($site, 'FRESH', 'completed', now());

        $synced = $this->fakeSync($site);

        $this->artisan('product-questions:sync-products', [
            '--site' => 'dressafford',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(['FRESH', 'MISSING'], $synced());
    }

    private function site(): CommerceSite
    {
        return CommerceSite::create([
            'name' => 'DressAfford',
            'site_key' => 'dressafford',
            'admin_api_url' => 'https://dressafford.test/api/admin',
            'api_token' => 'token',
            'enabled' => true,
        ]);
    }

    private function question(CommerceSite $site, int $externalId, string $sn): void
    {
        ProductQuestion::create([
            'commerce_site_id' => $site->id,
            'external_id' => $externalId,
            'sn' => $sn,
            'question' => 'Question',
            'reply_status' => 'unanswered',
            'raw_payload' => [],
            'synced_at' => now(),
        ]);
    }

    private function product(CommerceSite $site, string $sn, string $status, mixed $syncedAt): void
    {
        CommerceProduct::create([
            'commerce_site_id' => $site->id,
            'sn' => $sn,
            'product' => [],
            'payload_hash' => hash('sha256', $sn),
            'sync_status' => $status,
            'synced_at' => $syncedAt,
        ]);
    }

    /** @return callable(): array<int, string> */
    private function fakeSync(CommerceSite $expectedSite): callable
    {
        $synced = [];
        $this->mock(ProductCatalogSyncService::class, function (MockInterface $mock) use (&$synced, $expectedSite): void {
            $mock->shouldReceive('sync')->andReturnUsing(
                function (CommerceSite $site, string $sn) use (&$synced, $expectedSite): array {
                    $this->assertTrue($site->is($expectedSite));
                    $synced[] = $sn;

                    return ['status' => 'completed', 'product_id' => null, 'error' => null];
                },
            );
        });

        return static function () use (&$synced): array {
            return $synced;
        };
    }
}
