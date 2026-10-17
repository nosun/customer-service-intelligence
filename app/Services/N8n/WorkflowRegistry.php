<?php

namespace App\Services\N8n;

use App\Models\AiWorkflow;
use RuntimeException;

class WorkflowRegistry
{
    public function active(string $key, string $version): AiWorkflow
    {
        $workflow = AiWorkflow::query()
            ->where('workflow_key', $key)
            ->where('workflow_version', $version)
            ->first();

        if ($workflow === null || $workflow->status !== 'active') {
            throw new RuntimeException("Workflow {$key}@{$version} is not active.");
        }

        return $workflow;
    }

    public function runnable(string $key, string $version): AiWorkflow
    {
        $workflow = AiWorkflow::query()
            ->where('workflow_key', $key)
            ->where('workflow_version', $version)
            ->first();

        if ($workflow === null || ! in_array($workflow->status, ['evaluating', 'active'], true)) {
            throw new RuntimeException("Workflow {$key}@{$version} is not runnable.");
        }

        return $workflow;
    }
}
