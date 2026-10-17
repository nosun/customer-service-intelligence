<?php

namespace App\Services\ProductQuestions;

use RuntimeException;

class ProductQuestionDraftReplaySuite
{
    /** @return list<array<string,mixed>> */
    public function cases(?string $path = null): array
    {
        $path ??= resource_path('data/product-question-draft-replay-v1.json');
        $cases = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($cases) || count($cases) < 50) {
            throw new RuntimeException('Draft replay suite must contain at least 50 cases.');
        }
        $ids = array_column($cases, 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Draft replay case IDs must be unique.');
        }

        return array_values($cases);
    }

    /** @param list<array<string,mixed>> $predictions
     * @return array<string,float|int>
     */
    public function evaluate(array $predictions, ?string $path = null): array
    {
        $cases = collect($this->cases($path))->keyBy('id');
        $predictions = collect($predictions)->keyBy('id');
        $evaluated = 0;
        $ready = 0;
        $missedIntent = 0;
        $falseBlock = 0;
        $missedBlock = 0;
        $factErrors = 0;
        $policyViolations = 0;
        $accepted = 0;
        $durations = [];
        $cost = 0.0;

        foreach ($cases as $id => $case) {
            $prediction = $predictions->get($id);
            if (! is_array($prediction)) {
                continue;
            }
            $evaluated++;
            $blocked = array_key_exists('blocked', $prediction)
                ? (bool) $prediction['blocked']
                : ($prediction['status'] ?? null) === 'blocked';
            $ready += $blocked ? 0 : 1;
            $expected = (array) ($case['expected_intents'] ?? []);
            $actual = (array) ($prediction['intents'] ?? []);
            $missedIntent += array_diff($expected, $actual) === [] ? 0 : 1;
            $falseBlock += ! $case['must_block'] && $blocked ? 1 : 0;
            $missedBlock += $case['must_block'] && ! $blocked ? 1 : 0;
            $factErrors += (int) ($prediction['fact_errors'] ?? $prediction['fact_error'] ?? 0);
            $policyViolations += (int) ($prediction['policy_violations'] ?? $prediction['policy_violation'] ?? 0);
            $accepted += (bool) ($prediction['accepted_as_is'] ?? false) ? 1 : 0;
            $cost += (float) ($prediction['cost'] ?? 0);
            if (is_numeric($prediction['duration_ms'] ?? null)) {
                $durations[] = (float) $prediction['duration_ms'];
            }
        }

        sort($durations);

        return [
            'suite_size' => $cases->count(),
            'evaluated' => $evaluated,
            'ready_rate' => $this->rate($ready, $evaluated),
            'accept_as_is_rate' => $this->rate($accepted, $evaluated),
            'missed_intent_rate' => $this->rate($missedIntent, $evaluated),
            'false_block_rate' => $this->rate($falseBlock, $evaluated),
            'missed_required_block_rate' => $this->rate($missedBlock, $evaluated),
            'fact_error_rate' => $this->rate($factErrors, $evaluated),
            'policy_violation_rate' => $this->rate($policyViolations, $evaluated),
            'cost_per_draft' => $evaluated === 0 ? 0.0 : round($cost / $evaluated, 6),
            'p50_duration_ms' => $this->percentile($durations, 0.50),
            'p95_duration_ms' => $this->percentile($durations, 0.95),
        ];
    }

    private function rate(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : round($count / $total, 4);
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        return $values[(int) ceil(count($values) * $percentile) - 1];
    }
}
