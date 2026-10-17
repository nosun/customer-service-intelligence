<?php

namespace Tests\Unit;

use Tests\TestCase;

class N8nWorkflowDefinitionTest extends TestCase
{
    public function test_draft_workflow_has_separate_review_repair_and_final_review_nodes(): void
    {
        $path = base_path('../n8n/workflows/work__qa-draft-agent-v1.json');
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
