<?php

namespace Tests\Feature;

use App\Models\CustomerCase;
use App\Models\WorkItem;
use App\Services\TicketWork\TicketDraftWorkflowHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketDraftWorkflowHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_runtime_status_blocking_feedback_and_stuck_work(): void
    {
        $case = CustomerCase::create([
            'case_key' => 'health-ticket',
            'case_type' => 'ticket',
            'title' => 'Health Ticket',
            'status' => 'open',
        ]);
        WorkItem::create([
            'work_item_key' => (string) Str::uuid(),
            'customer_case_id' => $case->id,
            'work_type' => 'ticket_draft',
            'status' => 'completed',
            'result' => [
                'draft_status' => 'blocked',
                'human_review' => ['outcome' => 'rejected'],
            ],
            'started_at' => now()->subSeconds(3),
            'finished_at' => now(),
        ]);
        $stuck = WorkItem::create([
            'work_item_key' => (string) Str::uuid(),
            'customer_case_id' => $case->id,
            'work_type' => 'ticket_draft',
            'status' => 'queued',
        ]);
        $stuck->timestamps = false;
        $stuck->update(['created_at' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(3)]);

        $snapshot = app(TicketDraftWorkflowHealth::class)->snapshot();

        $this->assertSame(2, $snapshot['total']);
        $this->assertSame(1, $snapshot['status_counts']['completed']);
        $this->assertSame(1.0, $snapshot['blocked_draft_rate']);
        $this->assertSame(1.0, $snapshot['human_review_coverage']);
        $this->assertSame(0.0, $snapshot['human_acceptance_rate']);
        $this->assertSame(1, $snapshot['stuck_queued']);
        $this->assertFalse($snapshot['healthy']);
        $this->assertGreaterThanOrEqual(2900, $snapshot['p50_duration_ms']);
    }

    public function test_sdk_health_uses_deadlines_and_detects_active_work_outside_window(): void
    {
        $case = CustomerCase::create(['case_key' => 'sdk-health', 'case_type' => 'ticket', 'title' => 'Health', 'status' => 'open']);
        $item = WorkItem::create(['work_item_key' => (string) Str::uuid(), 'customer_case_id' => $case->id,
            'work_type' => 'ticket_agent', 'workflow_key' => 'work/ticket-agent-sdk', 'workflow_version' => '1.2.0',
            'status' => 'running', 'deadline_at' => now()->addMinute()]);
        $health = app(TicketDraftWorkflowHealth::class);
        $this->assertSame(1, $health->snapshot()['total']);
        $this->assertTrue($health->snapshot()['healthy']);
        $item->timestamps = false;
        $item->update(['created_at' => now()->subDays(2), 'deadline_at' => now()->subSecond()]);
        $snapshot = $health->snapshot();
        $this->assertSame(0, $snapshot['total']);
        $this->assertSame(1, $snapshot['stuck_running']);
        $this->assertFalse($snapshot['healthy']);
        $this->assertSame('unhealthy', $snapshot['technical_status']);
        $this->assertSame('work/ticket-agent-sdk', $snapshot['workflows'][0]['workflow_key']);
        $this->assertSame('1.2.0', $snapshot['workflows'][0]['workflow_version']);
        $this->assertSame('no_data', $snapshot['business_status']);
    }

    public function test_sdk_failures_and_business_review_are_included_even_when_dispatch_is_disabled(): void
    {
        config(['ticket-assistance.enabled' => false]);
        $case = CustomerCase::create(['case_key' => 'sdk-failure', 'case_type' => 'ticket', 'title' => 'Health', 'status' => 'open']);
        foreach (['completed', 'failed'] as $status) {
            WorkItem::create(['work_item_key' => (string) Str::uuid(), 'customer_case_id' => $case->id,
                'work_type' => 'ticket_agent', 'workflow_key' => 'work/ticket-agent-sdk', 'workflow_version' => '1.2.0',
                'status' => $status, 'result' => ['draft_status' => 'blocked']]);
        }
        $snapshot = app(TicketDraftWorkflowHealth::class)->snapshot();
        $this->assertSame(2, $snapshot['total']);
        $this->assertFalse($snapshot['healthy']);
        $this->assertSame(0.5, $snapshot['failure_rate']);
        $this->assertSame(1.0, $snapshot['blocked_draft_rate']);
        $this->assertSame(0.0, $snapshot['human_review_coverage']);
        $this->assertSame('awaiting_review', $snapshot['business_status']);
        $this->artisan('tickets:draft-workflow-health', ['--fail-on-unhealthy' => true])->assertFailed();
    }

    public function test_empty_window_is_explicitly_disabled_or_unobserved(): void
    {
        config(['ticket-assistance.enabled' => false]);
        $health = app(TicketDraftWorkflowHealth::class);
        $this->assertSame('disabled', $health->snapshot()['technical_status']);
        config(['ticket-assistance.enabled' => true]);
        $this->assertSame('no_data', $health->snapshot()['technical_status']);
        $this->assertSame('no_data', $health->snapshot()['business_status']);
    }
}
