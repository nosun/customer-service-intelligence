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
}
