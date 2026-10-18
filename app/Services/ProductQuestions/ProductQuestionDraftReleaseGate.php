<?php

namespace App\Services\ProductQuestions;

use RuntimeException;

class ProductQuestionDraftReleaseGate
{
    public const SCHEMA_VERSION = '1.0.0';

    /** @return array<string, float> */
    public function thresholds(): array
    {
        return [
            'prediction_coverage' => 1.0,
            'quality_review_coverage' => 1.0,
            'workflow_failure_rate' => 0.0,
            'product_identity_repetition_rate' => 0.0,
            'unsupported_follow_up_action_rate' => 0.0,
            'fact_error_rate' => 0.0,
            'policy_violation_rate' => 0.0,
            'missed_required_block_rate' => 0.0,
            'missed_intent_rate' => 0.02,
        ];
    }

    /** @param array<string, float|int> $metrics
     * @return array{passed:bool,failures:list<string>,thresholds:array<string,float>}
     */
    public function evaluate(array $metrics): array
    {
        $failures = [];
        foreach ($this->thresholds() as $metric => $threshold) {
            $actual = (float) ($metrics[$metric] ?? 0);
            $minimum = in_array($metric, ['prediction_coverage', 'quality_review_coverage'], true);
            if (($minimum && $actual < $threshold) || (! $minimum && $actual > $threshold)) {
                $operator = $minimum ? '>=' : '<=';
                $failures[] = "{$metric}={$actual} requires {$operator}{$threshold}";
            }
        }

        return [
            'passed' => $failures === [],
            'failures' => $failures,
            'thresholds' => $this->thresholds(),
        ];
    }

    /** @return array<string, mixed> */
    public function readReport(string $path): array
    {
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved)) {
            throw new RuntimeException('Draft replay release report does not exist.');
        }
        $report = json_decode((string) file_get_contents($resolved), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($report) || ($report['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new RuntimeException('Draft replay release report has an unsupported schema.');
        }

        return $report;
    }

    public function assertAllowsActivation(
        array $report,
        string $workflowKey,
        string $workflowVersion,
        string $definitionChecksum,
        string $suiteChecksum,
    ): void {
        if (($report['passed'] ?? false) !== true) {
            throw new RuntimeException('Draft replay release gate did not pass.');
        }
        if (($report['workflow']['key'] ?? null) !== $workflowKey
            || ($report['workflow']['version'] ?? null) !== $workflowVersion
            || ($report['workflow']['definition_checksum'] ?? null) !== $definitionChecksum) {
            throw new RuntimeException('Draft replay report does not match the workflow being activated.');
        }
        if (($report['suite']['checksum'] ?? null) !== $suiteChecksum) {
            throw new RuntimeException('Draft replay report was generated from a different replay suite.');
        }
    }
}
