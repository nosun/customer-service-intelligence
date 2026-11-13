<?php

namespace Tests\Feature;

use App\Models\CustomerContactEffect;
use App\Services\CustomerContacts\ConsoleContactReader;
use App\Services\CustomerContacts\MultiDressConsoleExecutor;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MultiDressReadyRound;
use Tests\TestCase;

class MultiDressConsoleExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['customer_contacts.mode' => 'live', 'customer_contacts.console_note_enabled' => true,
            'services.console_new.base_url' => 'https://console.example.invalid/api/admin', 'services.console_new.token' => 'fixture']);
    }

    public function test_preview_preserves_human_note_and_never_changes_other_fields(): void
    {
        $round = MultiDressReadyRound::create();
        $round->update(['communication_state' => 'sent', 'sent_at' => now(), 'note_state' => 'pending']);
        $this->mock(ConsoleContactReader::class)->shouldReceive('contact')->andReturn(['id' => 301, 'oid' => 900001,
            'source' => 'multi_dress', 'version' => 7, 'internal_note' => '同事原文']);
        Http::fake(['https://console.example.invalid/api/admin/customer-contacts/301' => fn ($r) => Http::response(['success' => true,
            'data' => ['allowed' => true, 'confirm_token' => 'fixture-confirm', 'operation_id' => 'fixture-operation', 'expires_at' => now()->addMinutes(5)->toIso8601String(),
                'diff' => ['before' => ['internal_note' => '同事原文'], 'after' => ['internal_note' => $r['changes']['internal_note']]]]])]);
        $effect = app(MultiDressConsoleExecutor::class)->previewNote($round->id);
        $this->assertSame('previewed', CustomerContactEffect::findOrFail($effect['effect_id'])->state);
        Http::assertSent(function ($r) {
            $changes = $r['changes'];
            $this->assertSame(['internal_note', 'recorder'], array_keys($changes));
            $this->assertStringStartsWith('同事原文', $changes['internal_note']);
            $this->assertSame('nosun-agent', $changes['recorder']);

            return $r['preview'] === true && $r['version'] === 7;
        });
    }

    public function test_operation_id_without_actor_and_exact_preview_cannot_confirm(): void
    {
        $round = MultiDressReadyRound::create();
        $effect = CustomerContactEffect::create(['round_id' => $round->id, 'effect_kind' => 'sent_note', 'state' => 'previewed',
            'payload' => [], 'payload_hash' => str_repeat('a', 64), 'idempotency_key' => '00000000-0000-4000-8000-000000000001',
            'operation_id' => 'not-human-authorization']);
        $this->expectException(AuthorizationException::class);
        try {
            app(MultiDressConsoleExecutor::class)->confirm($effect->id, new Administrator, $effect->payload_hash);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_timeout_contract_is_not_faked_by_bare_release(): void
    {
        $round = MultiDressReadyRound::create();
        $result = app(MultiDressConsoleExecutor::class)->previewTimeout($round->id);
        $this->assertSame('held', $result['decision']);
        $this->assertSame('source_timeout_contract_missing', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_human_confirm_records_actor_and_preserves_first_post_write_baseline(): void
    {
        $round = MultiDressReadyRound::create();
        $round->update(['communication_state' => 'sent', 'sent_at' => now(), 'note_state' => 'pending']);
        $contact = ['id' => 301, 'oid' => 900001, 'source' => 'multi_dress', 'version' => 7, 'internal_note' => '同事原文'];
        $this->mock(ConsoleContactReader::class)->shouldReceive('contact')->andReturnUsing(function () use (&$contact) {
            return $contact;
        });
        Http::fake(['https://console.example.invalid/api/admin/customer-contacts/301' => function ($r) use (&$contact) {
            if ($r['preview']) {
                return Http::response(['success' => true, 'data' => ['allowed' => true, 'confirm_token' => 'fixture-confirm',
                    'expires_at' => now()->addMinutes(5)->toIso8601String(), 'diff' => ['after' => ['internal_note' => $r['changes']['internal_note']]]]]);
            }
            $this->assertSame('fixture-confirm', $r['confirm_token']);
            $contact['internal_note'] = $r['changes']['internal_note'];
            $contact['version']++;

            return Http::response(['success' => true, 'data' => ['updated' => true]]);
        }]);
        $actor = Administrator::create(['username' => 'note-reviewer', 'password' => bcrypt('test'), 'name' => 'Reviewer']);
        $role = Role::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $actor->roles()->attach($role);
        $service = app(MultiDressConsoleExecutor::class);
        $preview = $service->previewNote($round->id);
        $this->assertSame('recorded', $service->confirm($preview['effect_id'], $actor, $preview['payload_hash'])['decision']);
        $effect = CustomerContactEffect::findOrFail($preview['effect_id']);
        $this->assertEquals($actor->id, $effect->authorized_by);
        $baseline = $effect->receipt['contact_baseline'];
        $contact['internal_note'] .= '\n同事追加内容';
        $contact['version']++;
        $service->reconcile($effect->id);
        $this->assertSame($baseline, $effect->fresh()->receipt['contact_baseline']);
        $this->assertNotSame($baseline, MultiDressConsoleExecutor::contactBaseline($contact));
        Http::assertSentCount(2);
    }

    #[DataProvider('confirmFailures')]
    public function test_known_rejection_can_repreview_but_uncertain_confirmation_cannot(int $status, string $code, string $expected): void
    {
        $round = MultiDressReadyRound::create();
        $round->update(['communication_state' => 'sent', 'sent_at' => now(), 'note_state' => 'pending']);
        $contact = ['id' => 301, 'oid' => 900001, 'source' => 'multi_dress', 'version' => 7, 'internal_note' => '同事原文'];
        $this->mock(ConsoleContactReader::class)->shouldReceive('contact')->andReturnUsing(function () use (&$contact) {
            return $contact;
        });
        Http::fake(['https://console.example.invalid/api/admin/customer-contacts/301' => function ($r) use ($status, $code, &$contact) {
            if ($r['preview']) {
                $this->assertStringStartsWith($contact['internal_note'], $r['changes']['internal_note']);

                return Http::response(['success' => true, 'data' => ['allowed' => true, 'confirm_token' => 'fixture-confirm',
                    'expires_at' => now()->addMinutes(5)->toIso8601String(), 'diff' => ['after' => ['internal_note' => $r['changes']['internal_note']]]]]);
            }
            $contact['internal_note'] .= '；同事新进展';
            $contact['version']++;

            return Http::response(['success' => false, 'errors' => [['code' => $code, 'message' => 'fixture failure']]], $status);
        }]);
        $actor = Administrator::create(['username' => 'conflict-reviewer', 'password' => bcrypt('test'), 'name' => 'Reviewer']);
        $actor->roles()->attach(Role::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']));
        $service = app(MultiDressConsoleExecutor::class);
        $preview = $service->previewNote($round->id);
        $this->assertSame($expected, $service->confirm($preview['effect_id'], $actor, $preview['payload_hash'])['decision']);
        $this->assertSame($expected, CustomerContactEffect::findOrFail($preview['effect_id'])->state);
        if ($expected === 'stale') {
            $this->assertSame('previewed', $service->previewNote($round->id)['decision']);
            Http::assertSentCount(3);
        } else {
            $this->expectExceptionMessage('Existing contact operation must be reconciled first');
            try {
                $service->previewNote($round->id);
            } finally {
                Http::assertSentCount(2);
            }
        }
    }

    public static function confirmFailures(): array
    {
        return [[409, 'CONTACT_CONFLICT', 'stale'], [500, 'SERVER_ERROR', 'unknown']];
    }
}
