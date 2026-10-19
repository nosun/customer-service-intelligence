<?php

namespace Tests\Unit;

use Tests\TestCase;

class N8nWorkflowDefinitionTest extends TestCase
{
    public function test_ticket_workflow_keeps_runtime_facts_verified_policies_and_repair_in_work_center(): void
    {
        $path = base_path('n8n/workflows/work__ticket-draft-v1.json');
        $workflow = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $nodes = collect($workflow['nodes'])->keyBy('name');

        foreach ([
            'Start Ticket WorkItem',
            'Plan Ticket Reply',
            'Resolve Ticket Facts',
            'Load Ticket Policies',
            'Generate Ticket Draft',
            'Review and Repair Ticket Draft Once',
            'Save Ticket Draft',
            'Mark Ticket Draft Failed',
        ] as $name) {
            $this->assertTrue($nodes->has($name), "Missing Ticket n8n node: {$name}");
        }
        $this->assertFalse($workflow['active']);
        $this->assertStringContainsString('/ticket-work-items/', $nodes['Start Ticket WorkItem']['parameters']['url']);
        $this->assertStringContainsString('verified Policies', $nodes['Build Ticket Draft Prompt']['parameters']['jsCode']);
        $this->assertStringContainsString('repair it at most once', $nodes['Build Ticket Review Repair Prompt']['parameters']['jsCode']);
        $this->assertStringContainsString('execution_id', $nodes['Save Ticket Draft']['parameters']['body']);
        $this->assertSame('continueErrorOutput', $nodes['Resolve Ticket Facts']['onError']);
        $this->assertSame('Mark Ticket Draft Failed', $workflow['connections']['Review and Repair Ticket Draft Once']['main'][1][0]['node']);
    }

    public function test_draft_workflow_has_separate_review_repair_and_final_review_nodes(): void
    {
        $path = base_path('n8n/workflows/work__qa-draft-agent-v1.json');
        $workflow = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $nodes = collect($workflow['nodes'])->keyBy('name');

        foreach (['Review Draft', 'Apply Review Guards', 'Reviewer Approved', 'Repair Draft Once', 'Final Review Draft', 'Apply Final Review Guards', 'Save Reviewed Draft', 'Mark Draft Failed'] as $name) {
            $this->assertTrue($nodes->has($name), "Missing n8n node: {$name}");
        }
        $this->assertStringContainsString('input_hash', $nodes['Save Reviewed Draft']['parameters']['body']);
        $this->assertSame('continueErrorOutput', $nodes['Build Review Prompt']['onError']);
        $this->assertSame('continueErrorOutput', $nodes['Build Repair Prompt']['onError']);
        $this->assertSame('continueErrorOutput', $nodes['Build Final Review Prompt']['onError']);
        $this->assertSame('Mark Draft Failed', $workflow['connections']['Final Review Draft']['main'][1][0]['node']);
        $this->assertSame('Apply Review Guards', $workflow['connections']['Review Draft']['main'][0][0]['node']);
        $this->assertSame('Apply Final Review Guards', $workflow['connections']['Final Review Draft']['main'][0][0]['node']);
        $this->assertStringContainsString('generic contact referral', $nodes['Apply Review Guards']['parameters']['jsCode']);
    }
}
