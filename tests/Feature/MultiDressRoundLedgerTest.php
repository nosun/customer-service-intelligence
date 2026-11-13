<?php

namespace Tests\Feature;

use App\Services\CustomerContacts\MultiDressPolicy;
use App\Services\CustomerContacts\MultiDressRoundManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\MultiDressScenario as Scenario;
use Tests\TestCase;

class MultiDressRoundLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
    }

    public function test_contact_and_template_changes_do_not_create_another_first_round(): void
    {
        $s = Scenario::snapshot();
        $manager = app(MultiDressRoundManager::class);
        $first = $manager->ingest($s, Scenario::communication());
        $s['contacts'][0]['id'] = 302;
        $s['policy']['version'] = 'v2';
        $again = $manager->ingest($s, Scenario::communication());
        $this->assertSame($first->id, $again->id);
        $this->assertDatabaseCount('customer_contact_rounds', 1);
        $this->assertDatabaseCount('customer_contact_effects', 0);
    }

    public function test_cross_site_ids_do_not_collide_and_mydorris_is_rejected(): void
    {
        $manager = app(MultiDressRoundManager::class);
        $s = Scenario::snapshot();
        $a = $manager->ingest($s, Scenario::communication());
        $s['identity'] = [...$s['identity'], 'sid' => 35, 'site_key' => 'dressafford', 'order_number' => 'dad20990101-1'];
        $b = $manager->ingest($s, Scenario::communication());
        $this->assertNotSame($a->id, $b->id);
        $s['identity']['console_key'] = 'mydorris';
        $this->expectException(\InvalidArgumentException::class);
        $manager->ingest($s, Scenario::communication());
    }

    public function test_reingest_does_not_reset_sent_state_or_deadline(): void
    {
        $manager = app(MultiDressRoundManager::class);
        $round = $manager->ingest(Scenario::snapshot(), Scenario::communication());
        $round->update(['communication_state' => 'sent', 'sent_at' => now(), 'wait_until' => now()->addHours(48)]);
        $deadline = $round->wait_until->toIso8601String();
        $again = $manager->ingest(Scenario::snapshot(), Scenario::communication());
        $this->assertSame('sent', $again->communication_state);
        $this->assertSame($deadline, $again->wait_until->toIso8601String());
    }

    public function test_policy_fails_closed_for_missing_evidence_and_all_terminal_states(): void
    {
        $policy = app(MultiDressPolicy::class);
        $cases = [
            ['order.on_hold', false, 'skip'], ['order.production_started', true, 'skip'],
            ['order.shipped', true, 'skip'], ['order.paid', false, 'skip'],
            ['contacts.0.released', true, 'skip'], ['contacts.0.deleted', true, 'skip'],
            ['contacts.0.source', 'comment', 'skip'], ['human_hold', true, 'manual'],
            ['items.0.size', '', 'manual'], ['intent_complete', true, 'not_needed'],
        ];
        foreach ($cases as [$key, $value, $expected]) {
            $s = Scenario::snapshot();
            data_set($s, $key, $value);
            $this->assertSame($expected, $policy->assess($s, Scenario::communication())['decision'], $key);
        }
        $c = Scenario::communication();
        $c['complete'] = false;
        $this->assertSame('manual', $policy->assess(Scenario::snapshot(), $c)['decision']);
        $this->assertSame('prepare_first_email', $policy->assess(Scenario::snapshot(), Scenario::communication())['decision']);
    }

    public function test_unknown_send_and_human_hold_cannot_queue_work_again(): void
    {
        $manager = app(MultiDressRoundManager::class);
        $round = $manager->ingest(Scenario::snapshot(), Scenario::communication());
        $round->update(['communication_state' => 'send_unknown']);
        $this->expectException(\RuntimeException::class);
        $manager->requestWork($round->refresh());
    }
}
