<?php

namespace Tests\Feature;

use App\Models\CustomerCase;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketDraftWorkItemReaperTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requeues_expired_lease_and_retries_the_webhook(): void
    {
        config([
            'services.n8n.base_url' => 'https://n8n.test',
            'services.n8n.webhook_token' => 'token',
            'services.n8n.ticket_draft_webhook_path' => '/webhook/ticket-draft-v1',
        ]);
        Http::fake(['*' => Http::response(['accepted' => true])]);
        $item = $this->item([
            'status' => 'running',
            'lease_owner' => 'dead-execution',
            'lease_expires_at' => now()->subMinute(),
            'attempt_count' => 1,
            'deadline_at' => now()->addMinutes(5),
        ]);
        $item->timestamps = false;
        $item->update(['updated_at' => now()->subMinutes(3)]);

        $this->artisan('tickets:reap-draft-work-items', ['--queued-minutes' => 1, '--running-minutes' => 1])
            ->expectsOutput('retried=1 failed=0')
            ->assertSuccessful();

        $this->assertSame('queued', $item->fresh()->status);
        $this->assertNull($item->fresh()->lease_owner);
        Http::assertSentCount(1);
    }

    public function test_it_fails_expired_work_at_the_deadline_without_retriggering(): void
    {
        Http::fake();
        $item = $this->item(['status' => 'queued', 'deadline_at' => now()->subMinute()]);
        $item->timestamps = false;
        $item->update(['updated_at' => now()->subMinutes(3)]);

        $this->artisan('tickets:reap-draft-work-items', ['--queued-minutes' => 1])
            ->expectsOutput('retried=0 failed=1')
            ->assertSuccessful();

        $this->assertSame('failed', $item->fresh()->status);
        Http::assertNothingSent();
    }

    private function item(array $overrides): WorkItem
    {
        $case = CustomerCase::create([
            'case_key' => (string) Str::uuid(),
            'case_type' => 'ticket',
            'title' => 'Ticket runtime test',
            'status' => 'open',
        ]);

        return WorkItem::create([
            'work_item_key' => (string) Str::uuid(),
            'customer_case_id' => $case->id,
            'work_type' => 'ticket_draft',
            'workflow_key' => 'work/ticket-draft-v1',
            'workflow_version' => '1.0.0',
            'input_snapshot' => [],
            'max_attempts' => 3,
            ...$overrides,
        ]);
    }
}
