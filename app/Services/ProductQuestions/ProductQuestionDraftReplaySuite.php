<?php

namespace App\Services\ProductQuestions;

use RuntimeException;

class ProductQuestionDraftReplaySuite
{
    /** @return list<array<string,mixed>> */
    public function cases(?string $path = null): array
    {
        $path ??= resource_path('data/product-question-draft-replay-v1.json');
        $suite = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $defaultFixture = is_array($suite['default_fixture'] ?? null) ? $suite['default_fixture'] : [];
        $cases = is_array($suite['cases'] ?? null) ? $suite['cases'] : $suite;
        if (! is_array($cases) || count($cases) < 50) {
            throw new RuntimeException('Draft replay suite must contain at least 50 cases.');
        }
        $ids = array_column($cases, 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Draft replay case IDs must be unique.');
        }

        return collect($cases)->map(function (array $case) use ($defaultFixture): array {
            $overrides = (array) ($case['fixture'] ?? []);
            $case['fixture'] = array_replace_recursive($defaultFixture, $overrides);
            foreach ((array) ($overrides['product'] ?? []) as $key => $value) {
                if (is_array($value) && (array_is_list($value) || $key === 'size_chart')) {
                    $case['fixture']['product'][$key] = $value;
                }
            }

            return $case;
        })->values()->all();
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
        $qualityReviewed = 0;
        $decisionQualityReviewed = 0;
        $relevant = 0;
        $helpful = 0;
        $naturalTone = 0;
        $workflowFailures = 0;
        $productIdentityRepetitions = 0;
        $unsupportedFollowUpActions = 0;
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
            $workflowFailures += in_array(($prediction['status'] ?? null), ['failed', 'stale', 'timed_out'], true) ? 1 : 0;
            $answer = trim((string) ($prediction['answer_en'] ?? ''));
            $productName = data_get($prediction, 'facts.product.name');
            $productSn = data_get($prediction, 'facts.product.sn') ?? data_get($prediction, 'facts.question.product_sn');
            $productIdentityRepetitions += (
                (is_string($productName) && mb_strlen(trim($productName)) >= 5 && mb_stripos($answer, trim($productName)) !== false)
                || (is_string($productSn) && $productSn !== '' && preg_match('/\b'.preg_quote($productSn, '/').'\b/i', $answer) === 1)
            ) ? 1 : 0;
            $unsupportedFollowUpActions += preg_match(
                '/\b(?:I|we|(?:our|the|production|support|factory) team|the factory|a human (?:agent|representative))\s*'
                .'(?:will|\x{2019}ll|\'ll|need to)\s*(?:check|verify|review|investigate|look into|follow up|get back|report back|confirm|contact|escalate)\b'
                .'|\b(?:will be|has been)\s+(?:checked|confirmed|reviewed|escalated|noted|forwarded)\b/iu',
                $answer,
            ) === 1 ? 1 : 0;
            $ready += $blocked ? 0 : 1;
            $expected = (array) ($case['expected_intents'] ?? []);
            $actual = (array) ($prediction['intents'] ?? []);
            $missedIntent += array_diff($expected, $actual) === [] ? 0 : 1;
            $falseBlock += ! $case['must_block'] && $blocked ? 1 : 0;
            $missedBlock += $case['must_block'] && ! $blocked ? 1 : 0;
            $factReview = $prediction['fact_errors'] ?? $prediction['fact_error'] ?? null;
            $policyReview = $prediction['policy_violations'] ?? $prediction['policy_violation'] ?? null;
            $factErrors += is_numeric($factReview) ? (int) $factReview : 0;
            $policyViolations += is_numeric($policyReview) ? (int) $policyReview : 0;
            $qualityReviewed += is_numeric($factReview) && is_numeric($policyReview) ? 1 : 0;
            if (is_bool($prediction['relevance_ok'] ?? null) && is_bool($prediction['guidance_ok'] ?? null) && is_bool($prediction['tone_ok'] ?? null)) {
                $decisionQualityReviewed++;
                $relevant += $prediction['relevance_ok'] ? 1 : 0;
                $helpful += $prediction['guidance_ok'] ? 1 : 0;
                $naturalTone += $prediction['tone_ok'] ? 1 : 0;
            }
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
            'prediction_coverage' => $this->rate($evaluated, $cases->count()),
            'quality_review_coverage' => $this->rate($qualityReviewed, $evaluated),
            'decision_quality_review_coverage' => $this->rate($decisionQualityReviewed, $evaluated),
            'relevance_pass_rate' => $this->rate($relevant, $decisionQualityReviewed),
            'guidance_pass_rate' => $this->rate($helpful, $decisionQualityReviewed),
            'natural_tone_pass_rate' => $this->rate($naturalTone, $decisionQualityReviewed),
            'workflow_failure_rate' => $this->rate($workflowFailures, $evaluated),
            'product_identity_repetition_rate' => $this->rate($productIdentityRepetitions, $evaluated),
            'unsupported_follow_up_action_rate' => $this->rate($unsupportedFollowUpActions, $evaluated),
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
