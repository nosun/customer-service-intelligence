<?php

namespace App\Services\TicketWork;

use RuntimeException;

class TicketDraftReplaySuite
{
    /** @return list<array<string,mixed>> */
    public function cases(?string $path = null): array
    {
        $path ??= resource_path('data/ticket-draft-replay-v1.json');
        $suite = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $defaultFixture = is_array($suite['default_fixture'] ?? null) ? $suite['default_fixture'] : [];
        $cases = is_array($suite['cases'] ?? null) ? $suite['cases'] : $suite;
        if (! is_array($cases) || count($cases) < 50) {
            throw new RuntimeException('Ticket Draft replay suite must contain at least 50 cases.');
        }
        $ids = array_column($cases, 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Ticket Draft replay case IDs must be unique.');
        }

        return collect($cases)->map(function (array $case) use ($defaultFixture): array {
            foreach (['id', 'category', 'question', 'expected_intents', 'must_block'] as $field) {
                if (! array_key_exists($field, $case)) {
                    throw new RuntimeException("Ticket Draft replay case is missing {$field}.");
                }
            }
            $case['fixture'] = array_replace($defaultFixture, (array) ($case['fixture'] ?? []));

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
        $workflowFailures = 0;
        $privacyLeaks = 0;
        $unsupportedFollowUpActions = 0;
        $accepted = 0;
        $responseErrors = 0;
        $durations = [];

        foreach ($cases as $id => $case) {
            $prediction = $predictions->get($id);
            if (! is_array($prediction)) {
                continue;
            }
            $evaluated++;
            $status = (string) ($prediction['status'] ?? '');
            $blocked = array_key_exists('blocked', $prediction)
                ? (bool) $prediction['blocked']
                : in_array($status, ['blocked', 'stale', 'needs_review'], true);
            $expectedStale = (bool) ($case['simulate_stale'] ?? false);
            $workflowFailures += in_array($status, ['failed', 'timed_out'], true)
                || ($status === 'stale' && ! $expectedStale) ? 1 : 0;
            $answer = trim((string) ($prediction['answer_localized'] ?? $prediction['answer_en'] ?? ''));
            $privacyLeaks += $this->containsPrivateIdentifier($answer) ? 1 : 0;
            $unsupportedFollowUpActions += $this->promisesUnsupportedAction($answer) ? 1 : 0;
            $ready += $blocked ? 0 : 1;
            $actualMode = data_get($prediction, 'reply_plan.response_decision.mode');
            $expectedMode = $case['expected_response_mode'] ?? null;
            $responseErrors += ($expectedMode !== null && ($actualMode !== $expectedMode
                || ($expectedMode === 'no_reply' ? $status !== 'no_reply' : $status !== 'draft')))
                || ($status === 'no_reply' && ($answer !== '' || $actualMode !== 'no_reply')) ? 1 : 0;
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
            $accepted += (bool) ($prediction['accepted_as_is'] ?? false) ? 1 : 0;
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
            'workflow_failure_rate' => $this->rate($workflowFailures, $evaluated),
            'privacy_leak_rate' => $this->rate($privacyLeaks, $evaluated),
            'unsupported_follow_up_action_rate' => $this->rate($unsupportedFollowUpActions, $evaluated),
            'ready_rate' => $this->rate($ready, $evaluated),
            'accept_as_is_rate' => $this->rate($accepted, $evaluated),
            'response_decision_error_rate' => $this->rate($responseErrors, $evaluated),
            'missed_intent_rate' => $this->rate($missedIntent, $evaluated),
            'false_block_rate' => $this->rate($falseBlock, $evaluated),
            'missed_required_block_rate' => $this->rate($missedBlock, $evaluated),
            'fact_error_rate' => $this->rate($factErrors, $evaluated),
            'policy_violation_rate' => $this->rate($policyViolations, $evaluated),
            'p50_duration_ms' => $this->percentile($durations, 0.50),
            'p95_duration_ms' => $this->percentile($durations, 0.95),
        ];
    }

    private function containsPrivateIdentifier(string $answer): bool
    {
        return preg_match('/\b[a-z]{2,12}\d{8}-\d{3,12}\b/i', $answer) === 1
            || preg_match('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', $answer) === 1
            || $this->containsPhoneNumber($answer);
    }

    private function promisesUnsupportedAction(string $answer): bool
    {
        return preg_match(
            '/\b(?:I|we|(?:our|the|support|production|factory) team|the factory|the carrier)\s*'
            .'(?:will|\x{2019}ll|\'ll|need to)\s*(?:check|verify|review|investigate|look into|follow up|get back|report back|confirm|contact|escalate|ask)\b'
            .'|\bwill be\s+(?:checked|confirmed|reviewed|escalated|noted|forwarded)\b'
            .'|\bhas been\s+(?:escalated|forwarded)\b/iu',
            $answer,
        ) === 1;
    }

    private function containsPhoneNumber(string $answer): bool
    {
        preg_match_all('/(?<!\w)(?:\+?\d[\d\s().-]{7,}\d)(?!\w)/', $answer, $matches);

        return collect($matches[0] ?? [])->contains(function (string $candidate): bool {
            $digits = preg_replace('/\D/', '', $candidate) ?? '';

            return str_starts_with(trim($candidate), '+') ? strlen($digits) >= 8 : strlen($digits) >= 10;
        });
    }

    private function rate(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : round($count / $total, 4);
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        return $values === [] ? 0.0 : $values[(int) ceil(count($values) * $percentile) - 1];
    }
}
