<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MultiDressReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_uses_real_services_without_external_side_effects_or_persistent_cases(): void
    {
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Storage::fake('local');
        Storage::disk('local')->put('original-sentinel.txt', 'preserve');
        $this->artisan('contacts:multi-dress-replay', ['--fixture' => resource_path('data/seawave-multi-dress-replay-ebd1295-v1.json')])
            ->expectsOutputToContain('assertions_passed')->assertSuccessful();
        Http::assertNothingSent();
        Process::assertNothingRan();
        $this->assertDatabaseCount('customer_contact_rounds', 0);
        Storage::disk('local')->assertExists('original-sentinel.txt');
    }

    public function test_health_reports_unknown_and_overdue_separately(): void
    {
        $this->artisan('contacts:multi-dress-health')->expectsOutputToContain('unknown_sends')->assertSuccessful();
    }
}
