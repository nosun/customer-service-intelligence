<?php

namespace App\Services\KnowledgeResearch;

use RuntimeException;

class KnowledgeResearchOutputValidator
{
    private const CATEGORIES = ['presales', 'checkout', 'production', 'logistics', 'after_sales', 'dispute', 'other', 'unknown'];

    /** @param array<string, mixed> $output
     * @param  list<int>  $allowedNodeIds
     * @param  list<string>  $allowedExistingScenarioKeys
     * @return array{findings: list<array<string, mixed>>, business_questions: list<array<string, mixed>>}
     */
    public function validate(array $output, array $allowedNodeIds, array $allowedExistingScenarioKeys = []): array
    {
        $findings = $this->objectList($output['findings'] ?? null, 'findings');
        $questions = $this->objectList($output['business_questions'] ?? null, 'business_questions');
        $allowed = array_fill_keys($allowedNodeIds, true);
        $scenarioKeys = array_fill_keys($allowedExistingScenarioKeys, true);
        $outputScenarioKeys = [];

        foreach ($findings as &$finding) {
            $finding['scenario_key'] = $this->key($finding['scenario_key'] ?? null, 'scenario_key');
            if (isset($outputScenarioKeys[$finding['scenario_key']])) {
                throw new RuntimeException('Research output contains duplicate scenario_key values.');
            }
            $outputScenarioKeys[$finding['scenario_key']] = true;
            $scenarioKeys[$finding['scenario_key']] = true;
            if (! in_array($finding['category'] ?? null, self::CATEGORIES, true)) {
                throw new RuntimeException('Research finding contains an invalid category.');
            }
            foreach (['title', 'definition'] as $field) {
                $finding[$field] = $this->text($finding[$field] ?? null, $field);
            }
            $finding['problem_type'] = $this->nullableText($finding['problem_type'] ?? null);
            foreach (['inclusion_rules', 'exclusion_rules', 'required_facts', 'required_actions', 'prohibited_claims', 'uncertainties'] as $field) {
                $finding[$field] = $this->stringList($finding[$field] ?? null, $field);
            }
            $finding['evidence_decision_node_ids'] = $this->nodeIds(
                $finding['evidence_decision_node_ids'] ?? null,
                $allowed,
                true,
                "finding {$finding['scenario_key']} evidence_decision_node_ids",
            );
            $finding['decision_tree'] = $this->decisionTree(
                $finding['decision_tree'] ?? null,
                $allowed,
                $finding['scenario_key'],
            );
            $finding['reply_strategy'] = $this->replyStrategy($finding['reply_strategy'] ?? null);
            $finding['reply_templates'] = $this->replyTemplates(
                $finding['reply_templates'] ?? null,
                $allowed,
                $finding['scenario_key'],
            );
        }
        unset($finding);

        foreach ($questions as &$question) {
            $question['question_key'] = $this->key($question['question_key'] ?? null, 'question_key');
            foreach (['question', 'why_it_matters', 'provisional_safe_handling'] as $field) {
                $question[$field] = $this->text($question[$field] ?? null, $field);
            }
            $question['options'] = $this->stringList($question['options'] ?? null, 'options');
            $question['affected_scenario_keys'] = $this->stringList(
                $question['affected_scenario_keys'] ?? null,
                'affected_scenario_keys',
            );
            foreach ($question['affected_scenario_keys'] as $key) {
                if (! isset($scenarioKeys[$key])) {
                    throw new RuntimeException('Business question references an unknown scenario_key.');
                }
            }
            $question['evidence_decision_node_ids'] = $this->nodeIds(
                $question['evidence_decision_node_ids'] ?? null,
                $allowed,
                true,
                "business question {$question['question_key']} evidence_decision_node_ids",
            );
        }
        unset($question);

        return ['findings' => $findings, 'business_questions' => $questions];
    }

    /** @return list<array<string, mixed>> */
    private function objectList(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("Research output {$field} must be a list.");
        }
        foreach ($value as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new RuntimeException("Research output {$field} must contain objects.");
            }
        }

        return $value;
    }

    private function key(mixed $value, string $field): string
    {
        $value = $this->text($value, $field);
        if (preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $value) !== 1 || strlen($value) > 150) {
            throw new RuntimeException("Research output {$field} must be a short snake_case key.");
        }

        return $value;
    }

    private function text(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Research output {$field} must be a non-empty string.");
        }

        return trim($value);
    }

    private function nullableText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("Research output {$field} must be a list.");
        }
        $result = [];
        foreach ($value as $item) {
            $result[] = $this->text($item, $field);
        }

        return array_values(array_unique($result));
    }

    /** @param array<int, true> $allowed
     * @return list<int>
     */
    private function nodeIds(mixed $value, array $allowed, bool $required, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value) || ($required && $value === [])) {
            throw new RuntimeException("Research output {$field} must be a non-empty list.");
        }
        foreach ($value as $nodeId) {
            if (! is_int($nodeId) || ! isset($allowed[$nodeId])) {
                throw new RuntimeException("Research output {$field} references an invalid decision node.");
            }
        }

        return array_values(array_unique($value));
    }

    /** @param array<int, true> $allowed
     * @return list<array<string, mixed>>
     */
    private function decisionTree(mixed $value, array $allowed, string $scenarioKey): array
    {
        $items = $this->objectList($value, 'decision_tree');
        foreach ($items as $index => &$item) {
            $item['condition'] = $this->text($item['condition'] ?? null, 'condition');
            $item['outcome'] = $this->text($item['outcome'] ?? null, 'outcome');
            $item['evidence_decision_node_ids'] = $this->nodeIds(
                $item['evidence_decision_node_ids'] ?? null,
                $allowed,
                true,
                "finding {$scenarioKey} decision_tree[{$index}].evidence_decision_node_ids",
            );
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function replyStrategy(mixed $value): array
    {
        $items = $this->objectList($value, 'reply_strategy');
        foreach ($items as &$item) {
            $item['stage'] = $this->text($item['stage'] ?? null, 'stage');
            $item['goal'] = $this->text($item['goal'] ?? null, 'goal');
            $item['content_requirements'] = $this->stringList($item['content_requirements'] ?? null, 'content_requirements');
        }

        return $items;
    }

    /** @param array<int, true> $allowed
     * @return list<array<string, mixed>>
     */
    private function replyTemplates(mixed $value, array $allowed, string $scenarioKey): array
    {
        $items = $this->objectList($value, 'reply_templates');
        foreach ($items as $index => &$item) {
            foreach (['stage', 'subject', 'body'] as $field) {
                $item[$field] = $this->text($item[$field] ?? null, $field);
            }
            $item['conditions'] = $this->stringList($item['conditions'] ?? null, 'conditions');
            $item['evidence_decision_node_ids'] = $this->nodeIds(
                $item['evidence_decision_node_ids'] ?? null,
                $allowed,
                true,
                "finding {$scenarioKey} reply_templates[{$index}].evidence_decision_node_ids",
            );
        }

        return $items;
    }
}
