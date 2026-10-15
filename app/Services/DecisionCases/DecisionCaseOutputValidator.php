<?php

namespace App\Services\DecisionCases;

use RuntimeException;

class DecisionCaseOutputValidator
{
    private const CATEGORIES = ['presales', 'checkout', 'production', 'logistics', 'after_sales', 'dispute', 'other', 'unknown'];

    private const LIFECYCLE_STAGES = ['presales', 'checkout', 'ordered', 'production', 'shipped', 'delivered', 'after_sales', 'dispute', 'unknown'];

    private const RISKS = ['normal', 'high', 'critical'];

    private const DECISIONS = ['accepted', 'rejected', 'conditional', 'alternative', 'escalated', 'unknown'];

    private const EVIDENCE_ROLES = ['request', 'fact', 'decision', 'reason', 'action', 'outcome', 'exception'];

    /**
     * @param  array<string, mixed>  $output
     * @param  list<int>  $allowedEventIds
     * @param  array<int, string>  $eventAuthors
     * @return array<string, mixed>
     */
    public function validate(array $output, array $allowedEventIds, array $eventAuthors = []): array
    {
        $category = $output['primary_category'] ?? null;
        $risk = $output['case_risk'] ?? null;
        $nodes = $output['nodes'] ?? null;
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new RuntimeException('Decision case contains an invalid primary_category.');
        }
        if (! in_array($risk, self::RISKS, true)) {
            throw new RuntimeException('Decision case contains an invalid case_risk.');
        }
        if (! is_array($nodes) || ! array_is_list($nodes)) {
            throw new RuntimeException('Decision case nodes must be a list.');
        }
        $nodes = $this->mergeAgentOnlyNodes($nodes, $eventAuthors);

        $allowed = array_fill_keys($allowedEventIds, true);
        foreach ($nodes as $index => &$node) {
            if (! is_array($node) || array_is_list($node)) {
                throw new RuntimeException('Each decision node must be an object.');
            }
            $expectedSequence = $index + 1;
            if (($node['sequence'] ?? null) !== $expectedSequence) {
                throw new RuntimeException('Decision node sequences must start at 1 and remain contiguous.');
            }
            if (! is_string($node['request_summary'] ?? null) || trim($node['request_summary']) === '') {
                throw new RuntimeException('Each decision node requires request_summary.');
            }
            if (! in_array($node['observed_decision'] ?? null, self::DECISIONS, true)) {
                throw new RuntimeException('Decision node contains an invalid observed_decision.');
            }
            foreach (['observed_facts', 'missing_facts', 'evidence', 'uncertainties'] as $listField) {
                if (! is_array($node[$listField] ?? null) || ! array_is_list($node[$listField])) {
                    throw new RuntimeException("Decision node {$listField} must be a list.");
                }
            }
            foreach (['intents', 'required_facts', 'required_actions', 'knowledge_gaps', 'prohibited_claims'] as $listField) {
                $node[$listField] = $this->stringList($node[$listField] ?? []);
            }
            $lifecycleStage = $node['lifecycle_stage'] ?? 'unknown';
            if (! in_array($lifecycleStage, self::LIFECYCLE_STAGES, true)) {
                throw new RuntimeException('Decision node contains an invalid lifecycle_stage.');
            }
            $node['lifecycle_stage'] = $lifecycleStage;
            $node['response_strategy'] = $this->nullableString($node['response_strategy'] ?? null);

            $hasRequestEvidence = false;
            foreach ($node['evidence'] as $evidence) {
                if (! is_array($evidence)
                    || ! is_int($evidence['event_id'] ?? null)
                    || ! isset($allowed[$evidence['event_id']])
                    || ! in_array($evidence['role'] ?? null, self::EVIDENCE_ROLES, true)) {
                    throw new RuntimeException('Decision node contains invalid evidence.');
                }
                $hasRequestEvidence = $hasRequestEvidence
                    || ($evidence['role'] === 'request' && ($eventAuthors[$evidence['event_id']] ?? null) === 'client');
            }
            if (! $hasRequestEvidence) {
                foreach ($node['evidence'] as $evidence) {
                    if (($eventAuthors[$evidence['event_id']] ?? null) === 'client') {
                        $node['evidence'][] = [
                            'event_id' => $evidence['event_id'],
                            'role' => 'request',
                        ];
                        $hasRequestEvidence = true;

                        break;
                    }
                }
            }
            if (! $hasRequestEvidence) {
                throw new RuntimeException('Each decision node requires request evidence.');
            }

            foreach ($node['observed_facts'] as $fact) {
                if (! is_array($fact)
                    || ! is_string($fact['name'] ?? null)
                    || ! is_string($fact['value'] ?? null)
                    || ! is_array($fact['evidence_event_ids'] ?? null)
                    || $fact['evidence_event_ids'] === []) {
                    throw new RuntimeException('Decision node contains an invalid observed fact.');
                }
                foreach ($fact['evidence_event_ids'] as $eventId) {
                    if (! is_int($eventId) || ! isset($allowed[$eventId])) {
                        throw new RuntimeException('Observed fact references an invalid event.');
                    }
                }
            }

            $confidence = $node['extraction_confidence'] ?? null;
            if (! is_int($confidence) && ! is_float($confidence)) {
                throw new RuntimeException('Decision node extraction_confidence must be numeric.');
            }
            $node['extraction_confidence'] = max(0, min(1, (float) $confidence));
            $node['is_exception'] = (bool) ($node['is_exception'] ?? false);
        }
        unset($node);

        $output['case_summary'] = is_string($output['case_summary'] ?? null)
            ? trim($output['case_summary'])
            : null;
        $output['primary_problem_type'] = is_string($output['primary_problem_type'] ?? null)
            ? trim($output['primary_problem_type'])
            : null;
        $output['nodes'] = $nodes;

        return $output;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('Decision node knowledge fields must be lists.');
        }

        $result = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new RuntimeException('Decision node knowledge fields must contain strings.');
            }
            $result[] = trim($item);
        }

        return array_values(array_unique($result));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param list<mixed> $nodes
     * @param  array<int, string>  $eventAuthors
     * @return list<mixed>
     */
    private function mergeAgentOnlyNodes(array $nodes, array $eventAuthors): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || array_is_list($node)) {
                $result[] = $node;

                continue;
            }
            $evidence = is_array($node['evidence'] ?? null) ? $node['evidence'] : [];
            $citesClient = collect($evidence)->contains(function ($item) use ($eventAuthors): bool {
                return is_array($item)
                    && is_int($item['event_id'] ?? null)
                    && ($eventAuthors[$item['event_id']] ?? null) === 'client';
            });
            if ($citesClient || $result === []) {
                $result[] = $node;

                continue;
            }

            $previousIndex = array_key_last($result);
            if (! is_array($result[$previousIndex]) || array_is_list($result[$previousIndex])) {
                $result[] = $node;

                continue;
            }
            $previous = $result[$previousIndex];
            foreach (['observed_facts', 'missing_facts', 'required_facts', 'required_actions', 'knowledge_gaps', 'prohibited_claims', 'evidence', 'uncertainties'] as $field) {
                $previous[$field] = array_values(array_merge(
                    is_array($previous[$field] ?? null) ? $previous[$field] : [],
                    is_array($node[$field] ?? null) ? $node[$field] : [],
                ));
            }
            foreach (['observed_action', 'stated_reason', 'observed_outcome', 'response_strategy'] as $field) {
                $previous[$field] = $this->combinedText($previous[$field] ?? null, $node[$field] ?? null);
            }
            if (($node['observed_decision'] ?? 'unknown') !== 'unknown') {
                $previous['observed_decision'] = $node['observed_decision'];
            }
            $previous['is_exception'] = (bool) ($previous['is_exception'] ?? false) || (bool) ($node['is_exception'] ?? false);
            $previous['extraction_confidence'] = min(
                (float) ($previous['extraction_confidence'] ?? 0),
                (float) ($node['extraction_confidence'] ?? 0),
            );
            $result[$previousIndex] = $previous;
        }

        foreach ($result as $index => &$node) {
            if (is_array($node) && ! array_is_list($node)) {
                $node['sequence'] = $index + 1;
            }
        }

        return array_values($result);
    }

    private function combinedText(mixed $first, mixed $second): ?string
    {
        $values = array_values(array_unique(array_filter([
            $this->nullableString($first),
            $this->nullableString($second),
        ])));

        return $values === [] ? null : implode('；', $values);
    }
}
