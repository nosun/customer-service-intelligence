<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RegisterN8nWorkflowCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_activating_a_new_version_deprecates_the_previous_active_version(): void
    {
        $path = base_path('../n8n/workflows/work__qa-draft-agent-v1.json');

        $this->assertSame(0, Artisan::call('n8n:register-workflow', [
            'key' => 'work/qa-draft-agent-v1',
            'version' => '1.2.0',
            'file' => $path,
            '--status' => 'active',
            '--n8n-id' => 'test-workflow',
            '--prompt-version' => 'test-prompt',
        ]));

        $this->assertDatabaseHas('ai_workflows', [
            'workflow_key' => 'work/qa-draft-agent-v1',
            'workflow_version' => '1.1.0',
            'status' => 'deprecated',
        ]);
        $this->assertDatabaseHas('ai_workflows', [
            'workflow_key' => 'work/qa-draft-agent-v1',
            'workflow_version' => '1.2.0',
            'status' => 'active',
            'n8n_workflow_id' => 'test-workflow',
            'definition_checksum' => hash_file('sha256', $path),
        ]);
    }
}
