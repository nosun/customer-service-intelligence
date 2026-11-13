<?php

namespace Tests\Feature;

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MultiDressReadyRound;
use Tests\TestCase;

class MultiDressAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_read_rounds_and_admin_sees_draft_and_separate_states(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        $round = MultiDressReadyRound::create();
        $this->get('/admin/multi-dress-rounds')->assertRedirect('/admin/auth/login');
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->get('/admin/multi-dress-rounds')->assertOk()->assertSee($round->order_number);
        $this->get('/admin/multi-dress-rounds/'.$round->id)->assertOk()->assertSee('within 48 hours')->assertSee('邮件状态')->assertSee('备注状态');
    }

    public function test_manual_hold_invalidates_ready_draft_and_is_audited(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $round = MultiDressReadyRound::create();
        $this->actingAs($this->admin(), 'admin')->post('/admin/multi-dress-rounds/'.$round->id.'/hold', [
            'version' => $round->version, 'reason' => '同事已接手',
        ])->assertRedirect();
        $this->assertNotNull($round->fresh()->human_hold_at);
        $this->assertSame('manual', $round->fresh()->communication_state);
        $this->assertDatabaseHas('customer_contact_events', ['round_id' => $round->id, 'event_type' => 'human_hold']);
    }

    private function admin(): Administrator
    {
        $u = Administrator::create(['username' => 'multi-admin', 'password' => bcrypt('test-password'), 'name' => 'Tester']);
        $r = Role::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $u->roles()->attach($r);

        return $u;
    }
}
