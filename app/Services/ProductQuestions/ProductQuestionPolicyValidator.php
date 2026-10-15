<?php

namespace App\Services\ProductQuestions;

use App\Models\ProductQuestionAnalysis;
use RuntimeException;

class ProductQuestionPolicyValidator
{
    /** @param array<string, mixed> $output
     * @param  list<int>  $allowedQuestionIds
     * @return list<array<string, mixed>>
     */
    public function validate(
        array $output,
        array $allowedQuestionIds,
        string $expectedScenarioKey,
        string $expectedCategory,
    ): array {
        $rawStrategies = $output['strategies'] ?? $output['policies'] ?? $output['policy_candidates'] ?? null;
        if (is_array($rawStrategies) && ! array_is_list($rawStrategies)) {
            $rawStrategies = array_key_exists('scenario_key', $rawStrategies)
                ? [$rawStrategies]
                : array_values($rawStrategies);
        }
        $strategies = $this->objects($rawStrategies, 'strategies');
        if (count($strategies) !== 1) {
            throw new RuntimeException('strategies must contain exactly one policy for the supplied intent.');
        }
        $allowed = array_fill_keys($allowedQuestionIds, true);
        $seen = [];
        foreach ($strategies as &$strategy) {
            $strategy['scenario_key'] = $this->key($strategy['scenario_key'] ?? null);
            if ($strategy['scenario_key'] !== $expectedScenarioKey) {
                throw new RuntimeException('strategy scenario_key must match the supplied intent_key.');
            }
            if (isset($seen[$strategy['scenario_key']])) {
                throw new RuntimeException('strategies contains duplicate scenario_key values.');
            }
            $seen[$strategy['scenario_key']] = true;
            if (! in_array($strategy['category'] ?? null, array_keys(ProductQuestionAnalysis::CATEGORIES), true)) {
                throw new RuntimeException('strategy category is invalid.');
            }
            if ($strategy['category'] !== $expectedCategory) {
                throw new RuntimeException('strategy category must match the supplied category.');
            }
            foreach (['title', 'definition', 'applicability'] as $field) {
                $strategy[$field] = $this->text($strategy[$field] ?? null, $field);
            }
            foreach (['required_facts', 'policy_rules', 'action_steps', 'prohibited_claims', 'escalation_rules', 'conflicts', 'open_questions'] as $field) {
                $strategy[$field] = $this->strings($strategy[$field] ?? null, $field);
            }
            $strategy['decision_tree'] = $this->structuredList($strategy['decision_tree'] ?? null, ['condition', 'outcome'], 'decision_tree');
            $strategy['reply_strategy'] = $this->replyStrategy($strategy['reply_strategy'] ?? null);
            $strategy['reply_templates'] = $this->replyTemplates($strategy['reply_templates'] ?? null);
            $strategy['evidence_question_ids'] = $this->evidence($strategy['evidence_question_ids'] ?? null, $allowed);
            if (! is_numeric($strategy['confidence'] ?? null) || (float) $strategy['confidence'] < 0 || (float) $strategy['confidence'] > 1) {
                throw new RuntimeException('strategy confidence must be between 0 and 1.');
            }
            $strategy['confidence'] = round((float) $strategy['confidence'], 4);
        }

        return $strategies;
    }

    /** @return list<array<string, mixed>> */
    private function replyStrategy(mixed $value): array
    {
        $items = $this->objects($value, 'reply_strategy');
        foreach ($items as &$item) {
            $item['stage'] = $this->text($item['stage'] ?? null, 'reply_strategy.stage');
            $item['goal'] = $this->text($item['goal'] ?? null, 'reply_strategy.goal');
            $item['content_requirements'] = $this->strings($item['content_requirements'] ?? null, 'reply_strategy.content_requirements');
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function replyTemplates(mixed $value): array
    {
        $items = $this->objects($value, 'reply_templates');
        foreach ($items as &$item) {
            $item['conditions'] = $this->strings($item['conditions'] ?? null, 'reply_templates.conditions');
            $item['body'] = $this->text($item['body'] ?? null, 'reply_templates.body');
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function structuredList(mixed $value, array $fields, string $name): array
    {
        $items = $this->objects($value, $name);
        foreach ($items as &$item) {
            foreach ($fields as $field) {
                $item[$field] = $this->text($item[$field] ?? null, "{$name}.{$field}");
            }
        }

        return $items;
    }

    /** @param array<int, true> $allowed
     * @return list<int>
     */
    private function evidence(mixed $value, array $allowed): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new RuntimeException('evidence_question_ids must be a non-empty list.');
        }
        foreach ($value as $id) {
            if (! is_int($id) || ! isset($allowed[$id])) {
                throw new RuntimeException('evidence_question_ids references an invalid question.');
            }
        }

        return array_values(array_unique($value));
    }

    /** @return list<array<string, mixed>> */
    private function objects(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("{$field} must be a list.");
        }
        foreach ($value as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new RuntimeException("{$field} must contain objects.");
            }
        }

        return $value;
    }

    /** @return list<string> */
    private function strings(mixed $value, string $field): array
    {
        if (is_string($value) && trim($value) !== '') {
            $value = [$value];
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("{$field} must be a list.");
        }

        $result = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new RuntimeException("{$field} must contain strings.");
            }
            if (trim($item) !== '') {
                $result[] = trim($item);
            }
        }

        return array_values(array_unique($result));
    }

    private function key(mixed $value): string
    {
        $key = $this->text($value, 'scenario_key');
        if (preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $key) !== 1 || strlen($key) > 120) {
            throw new RuntimeException('scenario_key must be short snake_case.');
        }

        return $key;
    }

    private function text(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("{$field} must be a non-empty string.");
        }

        return trim($value);
    }
}
