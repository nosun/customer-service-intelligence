<?php

namespace App\Console\Commands;

use App\Models\AiWorkflow;
use App\Services\ProductQuestions\ProductQuestionDraftReleaseGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RegisterN8nWorkflow extends Command
{
    protected $signature = 'n8n:register-workflow
                            {key : Stable workflow key}
                            {version : Semantic workflow version}
                            {file : Exported n8n workflow JSON}
                            {--status=evaluating : experimental, evaluating, active, or deprecated}
                            {--n8n-id= : n8n workflow ID}
                            {--prompt-version= : Prompt version used by the workflow}
                            {--release-report= : Required passing replay report when status is active}';

    protected $description = 'Register an exported n8n workflow definition and checksum in Laravel';

    public function handle(ProductQuestionDraftReleaseGate $gate): int
    {
        $path = realpath((string) $this->argument('file'));
        if ($path === false || ! is_file($path)) {
            throw new RuntimeException('Workflow JSON file does not exist.');
        }
        $definition = file_get_contents($path);
        if ($definition === false || ! is_array(json_decode($definition, true))) {
            throw new RuntimeException('Workflow file is not valid JSON.');
        }
        $status = (string) $this->option('status');
        if (! in_array($status, ['experimental', 'evaluating', 'active', 'deprecated'], true)) {
            throw new RuntimeException('Invalid workflow status.');
        }

        $key = (string) $this->argument('key');
        $version = (string) $this->argument('version');
        $definitionChecksum = hash('sha256', $definition);
        $report = null;
        if ($status === 'active') {
            $reportPath = trim((string) $this->option('release-report'));
            if ($reportPath === '') {
                throw new RuntimeException('--release-report is required when activating a workflow.');
            }
            $report = $gate->readReport($reportPath);
            $gate->assertAllowsActivation(
                $report,
                $key,
                $version,
                $definitionChecksum,
                hash_file('sha256', resource_path('data/product-question-draft-replay-v1.json')),
            );
        }
        $workflow = DB::transaction(function () use ($definitionChecksum, $key, $report, $status, $version): AiWorkflow {
            if ($status === 'active') {
                AiWorkflow::query()
                    ->where('workflow_key', $key)
                    ->where('workflow_version', '!=', $version)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'deprecated',
                        'deprecated_at' => now(),
                    ]);
            }

            return AiWorkflow::query()->updateOrCreate(
                [
                    'workflow_key' => $key,
                    'workflow_version' => $version,
                ],
                [
                    'n8n_workflow_id' => $this->option('n8n-id') ?: null,
                    'prompt_version' => $this->option('prompt-version') ?: null,
                    'definition_checksum' => $definitionChecksum,
                    'status' => $status,
                    'replay_metrics' => $report['metrics'] ?? null,
                    'published_at' => $status === 'active' ? now() : null,
                    'deprecated_at' => $status === 'deprecated' ? now() : null,
                ],
            );
        });

        $this->info("registered={$workflow->workflow_key}@{$workflow->workflow_version} status={$workflow->status} checksum={$workflow->definition_checksum}");

        return self::SUCCESS;
    }
}
