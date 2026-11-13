<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\FollowUp;
use App\Services\FollowUps\FollowUpManager;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FollowUpLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-27 06:00:00', 'UTC'));
    }

    public function test_title_only_web_creation_defaults_owner_and_time_and_is_audited(): void
    {
        $this->actingAs($this->admin(), 'admin')->post('/admin/follow-ups', ['title' => '生产逾期，需要催办'])
            ->assertRedirect('/admin/follow-ups/1');
        $item = FollowUp::firstOrFail();
        $this->assertSame('open', $item->status);
        $this->assertSame('Tester', $item->owner);
        $this->assertSame('2026-10-28 02:00:00', $item->next_follow_up_at->format('Y-m-d H:i:s'));
        $this->assertSame('admin:1 Tester', $item->entries->sole()->actor);
        $this->get('/admin/follow-ups')->assertOk()->assertSee('生产逾期，需要催办')->assertSee('2026-10-28 10:00');
        $this->get('/admin/follow-ups/'.$item->id)->assertOk()->assertSee('记录新进展')->assertSee('处理记录');
        Http::assertNothingSent();
    }

    public function test_web_progress_changes_status_and_time_without_losing_prior_notes(): void
    {
        $item = $this->make(['note' => '已向工厂查询']);
        $this->actingAs($this->admin(), 'admin')->post('/admin/follow-ups/'.$item->id, [
            'version' => 1, 'note' => '工厂预计明天完工，下午确认发货',
            'status' => 'waiting', 'next_follow_up_at' => '2026-10-28T15:00',
        ])->assertRedirect('/admin/follow-ups/'.$item->id);
        $item->refresh();
        $this->assertSame('waiting', $item->status);
        $this->assertSame('2026-10-28 07:00:00', $item->next_follow_up_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, $item->version);
        $this->assertSame('工厂预计明天完工，下午确认发货', $item->latest_note);
        $this->assertSame('已向工厂查询', $item->entries()->oldest('id')->first()->note);
        $this->assertSame(['before' => 'open', 'after' => 'waiting'], $item->entries()->latest('id')->first()->changes['status']);
    }

    public function test_close_reopen_and_note_only_update_preserve_history(): void
    {
        $manager = app(FollowUpManager::class);
        $item = $this->make(['next_follow_up_at' => '2026-10-27 13:00']);
        $manager->update($item->id, ['status' => 'closed', 'note' => '问题已解决'], 1, 'ai:codex');
        $this->assertNotNull($item->fresh()->closed_at);
        $this->assertFalse($manager->query(['view' => 'due'])->whereKey($item->id)->exists());
        $this->assertTrue($manager->query(['view' => 'closed'])->whereKey($item->id)->exists());
        $manager->update($item->id, ['status' => 'active'], 2, 'admin:1');
        $this->assertNull($item->fresh()->closed_at);
        $this->assertTrue($manager->query(['view' => 'due'])->whereKey($item->id)->exists());
        $manager->update($item->id, ['note' => '客户再次反馈，继续调查'], 3, 'ai:codex');
        $this->assertSame('active', $item->fresh()->status);
        $this->assertSame(4, $item->entries()->count());
    }

    public function test_stale_ai_or_human_update_cannot_overwrite_current_record_or_add_a_log(): void
    {
        $item = $this->make();
        app(FollowUpManager::class)->update($item->id, ['note' => 'AI 已记录新的调查结果'], 1, 'ai:codex');
        $this->actingAs($this->admin(), 'admin')->from('/admin/follow-ups/'.$item->id)
            ->post('/admin/follow-ups/'.$item->id, ['version' => 1, 'status' => 'closed', 'note' => '旧页面内容'])
            ->assertSessionHasErrors('version');
        $this->assertSame('open', $item->fresh()->status);
        $this->assertSame('AI 已记录新的调查结果', $item->fresh()->latest_note);
        $this->assertSame(2, $item->entries()->count());
        $this->artisan('follow-ups', ['action' => 'update', 'id' => $item->id, '--expected-version' => 1, '--data' => '{"status":"closed"}'])
            ->expectsOutputToContain('validation_failed')->assertFailed();
        $this->assertSame(2, $item->entries()->count());
    }

    public function test_due_and_today_filters_use_shanghai_day_and_include_waiting_items(): void
    {
        $due = $this->make(['title' => '已到期待物流', 'status' => 'waiting', 'next_follow_up_at' => '2026-10-27 13:00']);
        $evening = $this->make(['title' => '今晚待办', 'next_follow_up_at' => '2026-10-27 23:59']);
        $tomorrow = $this->make(['title' => '明天待办', 'next_follow_up_at' => '2026-10-28 00:00']);
        $yesterday = $this->make(['title' => '昨天逾期', 'next_follow_up_at' => '2026-10-26 23:59']);
        $this->make(['title' => '已结束事项', 'status' => 'closed', 'next_follow_up_at' => '2026-10-27 12:00']);
        $manager = app(FollowUpManager::class);
        $this->assertEqualsCanonicalizing([$due->id, $yesterday->id], $manager->query(['view' => 'due'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$due->id, $evening->id], $manager->query(['view' => 'today'])->pluck('id')->all());
        $this->assertFalse($manager->query(['view' => 'today'])->whereKey($tomorrow->id)->exists());
    }

    public function test_urgent_unscheduled_search_and_pagination_are_available_to_ai(): void
    {
        $this->make(['title' => '普通事项']);
        $urgent = $this->make(['title' => '停止制作', 'urgent' => true, 'reference' => 'DressAfford DA123', 'next_follow_up_at' => null]);
        $manager = app(FollowUpManager::class);
        $this->assertSame($urgent->id, $manager->query()->first()->id);
        $this->assertSame($urgent->id, $manager->query(['view' => 'unscheduled'])->sole()->id);
        $this->assertSame($urgent->id, $manager->query(['view' => 'urgent', 'search' => 'DA123'])->sole()->id);
        $this->artisan('follow-ups', ['--search' => 'DA123', '--limit' => 1])
            ->expectsOutputToContain('停止制作')->assertSuccessful();
    }

    public function test_ticket_entry_carries_identity_and_closing_ticket_does_not_close_follow_up(): void
    {
        $ticket = $this->ticket();
        $this->actingAs($this->admin(), 'admin')->get('/admin/conversations/'.$ticket->id)
            ->assertOk()->assertSee('加入跟进 / 查看台账');
        $this->get('/admin/follow-ups?conversation_id='.$ticket->id)->assertOk()->assertSee('DA123');
        $this->post('/admin/follow-ups', ['title' => '回复后继续查询物流', 'conversation_id' => $ticket->id])->assertRedirect();
        $item = FollowUp::firstOrFail();
        $this->get('/admin/conversations/'.$ticket->id)->assertOk()->assertSee('回复后继续查询物流');
        $ticket->update(['status' => 'closed']);
        $this->assertSame('open', $item->fresh()->status);
        $this->artisan('follow-ups', ['--conversation-id' => $ticket->id])
            ->expectsOutputToContain('回复后继续查询物流')->assertSuccessful();
        $this->artisan('follow-ups', ['--search' => 'TICKET-123'])
            ->expectsOutputToContain('回复后继续查询物流')->assertSuccessful();
        $this->assertSame(0, $ticket->drafts()->count());
        Http::assertNothingSent();
    }

    public function test_cli_create_read_update_produces_shared_audited_data(): void
    {
        $this->artisan('follow-ups', ['action' => 'create', '--data' => '{"title":"包裹异常","note":"需要物流核查","next_follow_up_at":"2026-10-28T15:00:00+08:00"}'])
            ->expectsOutputToContain('包裹异常')->assertSuccessful();
        $item = FollowUp::firstOrFail();
        $this->artisan('follow-ups', ['action' => 'update', 'id' => $item->id, '--expected-version' => 1, '--data' => '{"status":"waiting","note":"已提交查询"}'])
            ->expectsOutputToContain('已提交查询')->assertSuccessful();
        $this->artisan('follow-ups', ['action' => 'show', 'id' => $item->id, '--limit' => 1])
            ->expectsOutputToContain('已提交查询')->assertSuccessful();
        $this->assertSame('ai:codex', $item->entries()->latest('id')->first()->actor);
        $this->actingAs($this->admin(), 'admin')->get('/admin/follow-ups/'.$item->id)->assertOk()->assertSee('已提交查询')->assertSee('ai:codex');
        Http::assertNothingSent();
    }

    public function test_invalid_inputs_are_rejected_without_partial_writes(): void
    {
        $this->actingAs($this->admin(), 'admin');
        foreach ([['title' => '   '], ['title' => 'x', 'status' => 'invalid'], ['title' => 'x', 'next_follow_up_at' => 'nonsense'], ['title' => 'x', 'conversation_id' => 99999]] as $input) {
            $this->post('/admin/follow-ups', $input)->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('follow_ups', 0);
        $this->assertDatabaseCount('follow_up_entries', 0);
        $this->artisan('follow-ups', ['action' => 'create', '--data' => '[]'])->expectsOutputToContain('validation_failed')->assertFailed();
        $this->artisan('follow-ups', ['action' => 'create', '--data' => 'broken'])->expectsOutputToContain('invalid_json')->assertFailed();
        $this->artisan('follow-ups', ['action' => 'show', 'id' => 999])->expectsOutputToContain('not_found')->assertFailed();
        $this->artisan('follow-ups', ['--limit' => 999])->expectsOutputToContain('validation_failed')->assertFailed();
    }

    public function test_empty_update_is_rejected_and_clearing_due_date_remains_discoverable(): void
    {
        $item = $this->make(['note' => '原记录']);
        try {
            app(FollowUpManager::class)->update($item->id, ['note' => ' '], 1, 'ai:codex');
            $this->fail('Empty update should fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('note', $e->errors());
        }
        $this->assertSame(1, $item->entries()->count());
        app(FollowUpManager::class)->update($item->id, ['next_follow_up_at' => null], 1, 'ai:codex');
        $this->assertNull($item->fresh()->next_follow_up_at);
        $this->assertSame('原记录', $item->fresh()->latest_note);
        $this->assertSame($item->id, app(FollowUpManager::class)->query(['view' => 'unscheduled'])->sole()->id);
    }

    public function test_guests_cannot_read_or_write_ledger(): void
    {
        $item = $this->make();
        $this->get('/admin/follow-ups')->assertRedirect('/admin/auth/login');
        $this->get('/admin/follow-ups/'.$item->id)->assertRedirect('/admin/auth/login');
        $this->post('/admin/follow-ups', ['title' => 'unauthorized'])->assertRedirect('/admin/auth/login');
        $this->post('/admin/follow-ups/'.$item->id, ['version' => 1, 'status' => 'closed'])->assertRedirect('/admin/auth/login');
        $this->assertSame('open', $item->fresh()->status);
        $this->assertDatabaseCount('follow_ups', 1);
    }

    public function test_untrusted_notes_and_references_render_as_plain_text(): void
    {
        $item = $this->make(['title' => '<script>alert(1)</script>', 'note' => '<img src=x onerror=alert(1)>', 'reference' => 'javascript:alert(1)']);
        $this->actingAs($this->admin(), 'admin')->get('/admin/follow-ups/'.$item->id)
            ->assertOk()->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false)->assertDontSee('href="javascript:alert(1)', false);
    }

    private function make(array $data = []): FollowUp
    {
        return app(FollowUpManager::class)->create($data + ['title' => '待调查事项'], 'ai:codex');
    }

    private function admin(): Administrator
    {
        $admin = Administrator::create(['username' => 'tester', 'password' => bcrypt('test'), 'name' => 'Tester']);
        $role = Role::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $admin->roles()->sync([$role->id]);

        return $admin;
    }

    private function ticket(): Conversation
    {
        $account = ChannelAccount::create(['channel' => 'helpdesk', 'external_account_id' => 'test']);

        return Conversation::create([
            'channel_account_id' => $account->id, 'external_id' => 'test-ticket', 'external_short_id' => 'TICKET-123',
            'subject' => '物流核查', 'status' => 'open', 'silo' => 'tickets', 'site_key' => 'dressafford.com',
            'linked_order_number' => 'DA123', 'raw_payload' => [],
        ]);
    }
}
