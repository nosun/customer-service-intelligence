<?php

namespace Tests\Feature;

use App\Models\AiWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

class RegisterN8nWorkflowCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_activating_a_new_version_deprecates_the_previous_active_version(): void
    {
        $path = base_path('n8n/workflows/work__qa-draft-agent-v1.json');
        $report = storage_path('framework/testing/passing-draft-release-report.json');
        file_put_contents($report, json_encode([
            'schema_version' => '1.0.0',
            'passed' => true,
            'workflow' => [
                'key' => 'work/qa-draft-agent-v1',
                'version' => '1.2.0',
                'definition_checksum' => hash_file('sha256', $path),
            ],
            'suite' => [
                'checksum' => hash_file('sha256', resource_path('data/product-question-draft-replay-v1.json')),
            ],
            'metrics' => ['fact_error_rate' => 0.0],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(0, Artisan::call('n8n:register-workflow', [
            'key' => 'work/qa-draft-agent-v1',
            'version' => '1.2.0',
            'file' => $path,
            '--status' => 'active',
            '--n8n-id' => 'test-workflow',
            '--prompt-version' => 'test-prompt',
            '--release-report' => $report,
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
        $this->assertSame(
            ['fact_error_rate' => 0],
            AiWorkflow::where('workflow_version', '1.2.0')->firstOrFail()->replay_metrics,
        );
    }

    public function test_activation_without_a_passing_release_report_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--release-report is required');

        Artisan::call('n8n:register-workflow', [
            'key' => 'work/qa-draft-agent-v1',
            'version' => '1.2.0',
            'file' => base_path('n8n/workflows/work__qa-draft-agent-v1.json'),
            '--status' => 'active',
        ]);
    }

    public function test_ticket_activation_uses_the_ticket_suite_checksum(): void
    {
        $path = base_path('n8n/workflows/work__ticket-draft-v1.json');
        $report = storage_path('framework/testing/passing-ticket-release-report.json');
        file_put_contents($report, json_encode([
            'schema_version' => '1.0.0',
            'passed' => true,
            'workflow' => [
                'key' => 'work/ticket-draft-v1',
                'version' => '1.0.0',
                'definition_checksum' => hash_file('sha256', $path),
            ],
            'suite' => [
                'checksum' => hash_file('sha256', resource_path('data/ticket-draft-replay-v1.json')),
            ],
            'metrics' => ['privacy_leak_rate' => 0.0],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(0, Artisan::call('n8n:register-workflow', [
            'key' => 'work/ticket-draft-v1',
            'version' => '1.0.0',
            'file' => $path,
            '--status' => 'active',
            '--release-report' => $report,
        ]));
        $this->assertDatabaseHas('ai_workflows', [
            'workflow_key' => 'work/ticket-draft-v1',
            'workflow_version' => '1.0.0',
            'status' => 'active',
        ]);
    }
}
