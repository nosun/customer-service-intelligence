<?php

namespace Tests\Feature;

use App\Models\CustomerContactEffect;
use App\Services\CustomerContacts\MultiDressConsoleExecutor;
use App\Services\CustomerContacts\MultiDressInput;
use App\Services\CustomerContacts\MultiDressWaitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MultiDressReadyRound;
use Tests\Support\MultiDressScenario as Scenario;
use Tests\TestCase;

class MultiDressWaitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Storage::fake('local');
    }

    public function test_two_dress_exact_deadline_is_eligible_but_not_executed(): void
    {
        $round = $this->sentRound();
        $this->input(Scenario::snapshot(), Scenario::communication());
        $service = app(MultiDressWaitService::class);
        $this->assertSame('waiting', $service->inspect($round->id, $round->wait_until->subSecond())['decision']);
        $this->assertSame('timeout_preview_ready', $service->inspect($round->id, $round->wait_until)['decision']);
        $this->assertDatabaseMissing('customer_contact_effects', ['effect_kind' => 'two_dress_timeout']);
        Http::assertNothingSent();
    }

    public function test_partial_reply_after_deadline_creates_one_human_handoff(): void
    {
        $round = $this->sentRound();
        $c = Scenario::communication();
        $c['reply_seen'] = true;
        $this->input(Scenario::snapshot(), $c);
        $service = app(MultiDressWaitService::class);
        $this->assertSame('human_handoff', $service->inspect($round->id, $round->wait_until->addSecond())['decision']);
        $service->inspect($round->id, $round->wait_until->addMinute());
        $this->assertSame('reply_seen', $round->fresh()->waiting_state);
        $this->assertDatabaseCount('follow_ups', 1);
        $this->assertDatabaseMissing('customer_contact_effects', ['effect_kind' => 'two_dress_timeout']);
    }

    public function test_three_dresses_never_inherit_two_dress_timeout_after_reduction(): void
    {
        $round = $this->sentRound();
        $trigger = $round->trigger_snapshot;
        $trigger['original_count'] = 3;
        $round->update(['trigger_snapshot' => $trigger]);
        $this->input(Scenario::snapshot(), Scenario::communication());
        $this->assertSame('original_three_or_more', app(MultiDressWaitService::class)->inspect($round->id, $round->wait_until)['reason']);
    }

    public function test_original_two_dress_proof_must_cover_the_same_items(): void
    {
        $round = $this->sentRound();
        $round->update(['trigger_snapshot' => [...$round->trigger_snapshot, 'item_ids' => [201, 202]]]);
        $this->input(Scenario::snapshot(), Scenario::communication());
        $this->assertSame('original_trigger_unproven', app(MultiDressWaitService::class)->inspect($round->id, $round->wait_until)['reason']);
    }

    public function test_each_missing_timeout_requirement_holds_order(): void
    {
        $cases = [
            ['snapshot', 'other_blockers', [['source' => 'eta']], 'other_blockers'],
            ['snapshot', 'items.0.size', '4', 'item_scope_changed'],
            ['snapshot', 'contacts.0.internal_note', '同事新进展', 'contact_changed_after_note'],
            ['snapshot', 'contacts.0.version', 2, 'contact_changed_after_note'],
            ['snapshot', 'policy.verified', false, 'policy_changed'],
            ['snapshot', 'policy.url', 'https://example.invalid/changed', 'policy_changed'],
            ['snapshot', 'customer.email', 'another@example.invalid', 'identity_changed'],
            ['communication', 'complete', false, 'communication_incomplete'],
            ['communication', 'fresh', false, 'communication_stale'],
            ['communication', 'identity_unique', false, 'identity_ambiguous'],
            ['communication', 'delivery_failure', true, 'delivery_failure'],
            ['communication', 'human_takeover', true, 'human_takeover'],
        ];
        foreach ($cases as [$part, $path, $value, $reason]) {
            $round = $this->sentRound();
            $s = Scenario::snapshot();
            $c = Scenario::communication();
            if ($part === 'snapshot') {
                data_set($s, $path, $value);
            } else {
                data_set($c, $path, $value);
            }
            $this->forgetMock(MultiDressInput::class);
            $this->input($s, $c);
            $result = app(MultiDressWaitService::class)->inspect($round->id, $round->wait_until);
            $this->assertSame('held', $result['decision'], $path);
            $this->assertSame($reason, $result['reason'], $path);
        }
    }

    private function sentRound()
    {
        $round = MultiDressReadyRound::create();
        $round->update(['communication_state' => 'sent', 'sent_at' => now(), 'wait_until' => now()->addHours(48), 'waiting_state' => 'waiting', 'note_state' => 'recorded']);
        CustomerContactEffect::firstOrCreate(['round_id' => $round->id, 'effect_kind' => 'sent_note'], [
            'state' => 'succeeded', 'payload_hash' => str_repeat('a', 64), 'idempotency_key' => (string) Str::uuid(),
            'payload' => ['contact_id' => 301], 'receipt' => ['contact_baseline' => MultiDressConsoleExecutor::contactBaseline(Scenario::snapshot()['contacts'][0])],
        ]);

        return $round->refresh();
    }

    private function input(array $s, array $c): void
    {
        $c['checkpoint']['captured_at'] = now()->addHours(48)->toIso8601String();
        $this->mock(MultiDressInput::class)->shouldReceive('capture')->andReturn(['snapshot' => $s, 'communication' => $c, 'hash' => str_repeat('a', 64)]);
    }
}
