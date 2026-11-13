<?php

namespace Tests\Feature;

use App\Services\CustomerContacts\GmailFirstRoundTransport;
use App\Services\CustomerContacts\MultiDressInput;
use App\Services\CustomerContacts\MultiDressMailExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MultiDressReadyRound;
use Tests\Support\MultiDressScenario as Scenario;
use Tests\TestCase;

class MultiDressMailExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Storage::fake('local');
        config(['customer_contacts.mode' => 'live', 'customer_contacts.send_enabled' => true,
            'customer_contacts.sites.ellenbridals.send_enabled' => true]);
    }

    public function test_unknown_send_is_not_repeated_and_does_not_start_timer(): void
    {
        $round = MultiDressReadyRound::create();
        $this->currentInput($round->source_hash);
        $this->mock(GmailFirstRoundTransport::class, function ($m) {
            $m->shouldReceive('verifyAccount')->once()->andReturn(['emailAddress' => 'ellenbridals.com@gmail.com']);
            $m->shouldReceive('send')->once()->andThrow(new \RuntimeException('provider timeout'));
        });
        $service = app(MultiDressMailExecutor::class);
        $service->send($round->id);
        $service->send($round->id);
        $this->assertSame('send_unknown', $round->fresh()->communication_state);
        $this->assertNull($round->fresh()->wait_until);
        $this->assertDatabaseCount('customer_contact_effects', 1);
    }

    public function test_success_is_durable_and_repeat_execution_does_not_reset_deadline(): void
    {
        $round = MultiDressReadyRound::create();
        $this->currentInput($round->source_hash);
        $this->mock(GmailFirstRoundTransport::class, function ($m) {
            $m->shouldReceive('verifyAccount')->once()->andReturn(['emailAddress' => 'ellenbridals.com@gmail.com']);
            $m->shouldReceive('send')->once()->andReturn(['state' => 'matched', 'gmail_id' => 'test-gmail', 'thread_id' => 'test-thread',
                'rfc822_id' => '<actual@test.invalid>', 'sent_at' => now()->toIso8601String()]);
        });
        $service = app(MultiDressMailExecutor::class);
        $service->send($round->id);
        $due = $round->fresh()->wait_until;
        $service->send($round->id);
        $this->assertSame('sent', $round->fresh()->communication_state);
        $this->assertTrue($due->equalTo($round->fresh()->wait_until));
        $this->assertTrue($round->fresh()->sent_at->addHours(48)->equalTo($due));
        $this->assertDatabaseHas('customer_contact_effects', ['round_id' => $round->id, 'effect_kind' => 'sent_note', 'state' => 'prepared']);
    }

    public function test_shadow_mode_never_resolves_real_transport(): void
    {
        config(['customer_contacts.mode' => 'shadow']);
        $round = MultiDressReadyRound::create();
        $this->app->bind(GmailFirstRoundTransport::class, fn () => throw new \RuntimeException('must not construct'));
        $this->assertSame('disabled', app(MultiDressMailExecutor::class)->send($round->id)['decision']);
        $this->assertDatabaseCount('customer_contact_effects', 0);
    }

    public function test_changes_after_review_block_send_before_intent_is_created(): void
    {
        $round = MultiDressReadyRound::create();
        $this->currentInput(str_repeat('b', 64));
        $this->mock(GmailFirstRoundTransport::class)->shouldNotReceive('send');
        $this->assertSame('stale', app(MultiDressMailExecutor::class)->send($round->id)['decision']);
        $this->assertDatabaseCount('customer_contact_effects', 0);
    }

    public function test_tampered_review_or_workflow_definition_cannot_send(): void
    {
        $round = MultiDressReadyRound::create();
        $this->currentInput($round->source_hash);
        $round->update(['review_hash' => str_repeat('b', 64)]);
        $this->app->bind(GmailFirstRoundTransport::class, fn () => throw new \RuntimeException('transport must not be resolved'));
        $this->expectExceptionMessage('Workflow definition or independent review proof');
        try {
            app(MultiDressMailExecutor::class)->send($round->id);
        } finally {
            $this->assertDatabaseCount('customer_contact_effects', 0);
        }
    }

    public function test_local_handoff_during_account_check_prevents_send_claim(): void
    {
        $round = MultiDressReadyRound::create();
        $this->currentInput($round->source_hash);
        $this->mock(GmailFirstRoundTransport::class, function ($m) use ($round) {
            $m->shouldReceive('verifyAccount')->once()->andReturnUsing(function () use ($round) {
                $round->update(['human_hold_at' => now()]);

                return [];
            });
            $m->shouldNotReceive('send');
        });
        $this->assertSame('already_attempted', app(MultiDressMailExecutor::class)->send($round->id)['decision']);
        $this->assertDatabaseCount('customer_contact_effects', 0);
    }

    private function currentInput(string $hash): void
    {
        $this->mock(MultiDressInput::class)->shouldReceive('capture')->andReturn(['snapshot' => Scenario::snapshot(),
            'communication' => Scenario::communication(), 'hash' => $hash]);
    }
}
