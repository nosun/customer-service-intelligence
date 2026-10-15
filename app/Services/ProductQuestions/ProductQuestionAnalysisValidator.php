<?php

namespace App\Services\ProductQuestions;

use App\Models\ProductQuestionAnalysis;
use RuntimeException;

class ProductQuestionAnalysisValidator
{
    private const ASSESSMENTS = ['good', 'partial', 'poor', 'not_applicable'];

    /** @return array<string, mixed> */
    public function validateRouting(array $output): array
    {
        $output['primary_category'] = $this->choice($output['primary_category'] ?? null, array_keys(ProductQuestionAnalysis::CATEGORIES), 'primary_category');
        $output['secondary_categories'] = $this->choices($output['secondary_categories'] ?? null, array_keys(ProductQuestionAnalysis::CATEGORIES), 'secondary_categories');
        $output['secondary_categories'] = array_values(array_diff($output['secondary_categories'], [$output['primary_category']]));
        $output['intent_key'] = $this->choice($output['intent_key'] ?? null, ProductQuestionAnalysis::INTENTS, 'intent_key');
        $output['customer_intent'] = $this->text($output['customer_intent'] ?? null, 'customer_intent');
        $output['question_scope'] = $this->choice($output['question_scope'] ?? null, array_keys(ProductQuestionAnalysis::SCOPES), 'question_scope');
        $output['answer_source'] = $this->choice($output['answer_source'] ?? null, array_keys(ProductQuestionAnalysis::ANSWER_SOURCES), 'answer_source');
        $output['work_queue'] = $this->choice($output['work_queue'] ?? null, array_keys(ProductQuestionAnalysis::WORK_QUEUES), 'work_queue');
        $output['risk_level'] = $this->choice($output['risk_level'] ?? null, array_keys(ProductQuestionAnalysis::RISKS), 'risk_level');
        foreach (['required_facts', 'missing_facts'] as $field) {
            $output[$field] = $this->strings($output[$field] ?? null, $field);
        }
        $output['factory_capability_key'] = $this->nullableChoice($output['factory_capability_key'] ?? null, ProductQuestionAnalysis::FACTORY_CAPABILITIES, 'factory_capability_key');
        $output['factory_question'] = $this->nullableText($output['factory_question'] ?? null);
        if (! is_numeric($output['confidence'] ?? null) || (float) $output['confidence'] < 0 || (float) $output['confidence'] > 1) {
            throw new RuntimeException('confidence must be a number between 0 and 1.');
        }
        $output['confidence'] = round((float) $output['confidence'], 4);

        $requiredSource = match ($output['work_queue']) {
            'catalog_answerable' => ['product_catalog'],
            'catalog_data_missing' => ['product_catalog'],
            'policy_review' => ['verified_policy', 'product_and_policy'],
            'factory_review' => ['factory_capability'],
            'customer_clarification' => ['customer_input'],
            default => null,
        };
        if ($requiredSource !== null && ! in_array($output['answer_source'], $requiredSource, true)) {
            throw new RuntimeException("{$output['work_queue']} has an inconsistent answer_source.");
        }
        if ($output['work_queue'] === 'factory_review' && (
            $output['factory_capability_key'] === null || $output['factory_question'] === null
        )) {
            throw new RuntimeException('factory_review requires factory_capability_key and factory_question.');
        }
        if ($output['work_queue'] !== 'factory_review') {
            $output['factory_capability_key'] = null;
            $output['factory_question'] = null;
        }

        return array_intersect_key($output, array_flip([
            'primary_category', 'secondary_categories', 'intent_key', 'customer_intent', 'question_scope',
            'answer_source', 'work_queue', 'risk_level', 'required_facts', 'missing_facts',
            'factory_capability_key', 'factory_question', 'confidence',
        ]));
    }

    /** @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    public function validate(array $output, bool $hasAnswer): array
    {
        $output['primary_category'] = $this->choice($output['primary_category'] ?? null, array_keys(ProductQuestionAnalysis::CATEGORIES), 'primary_category');
        $output['secondary_categories'] = $this->choices($output['secondary_categories'] ?? null, array_keys(ProductQuestionAnalysis::CATEGORIES), 'secondary_categories');
        $output['secondary_categories'] = array_values(array_diff($output['secondary_categories'], [$output['primary_category']]));
        $output['intent_key'] = $this->choice($output['intent_key'] ?? null, ProductQuestionAnalysis::INTENTS, 'intent_key');
        $output['customer_intent'] = $this->text($output['customer_intent'] ?? null, 'customer_intent');
        $output['question_scope'] = $this->choice($output['question_scope'] ?? null, array_keys(ProductQuestionAnalysis::SCOPES), 'question_scope');
        $output['answer_source'] = $this->choice($output['answer_source'] ?? null, array_keys(ProductQuestionAnalysis::ANSWER_SOURCES), 'answer_source');
        $output['work_queue'] = $this->choice($output['work_queue'] ?? null, array_keys(ProductQuestionAnalysis::WORK_QUEUES), 'work_queue');
        $output['answerability'] = $this->choice($output['answerability'] ?? null, array_keys(ProductQuestionAnalysis::ANSWERABILITY), 'answerability');
        $output['risk_level'] = $this->choice($output['risk_level'] ?? null, array_keys(ProductQuestionAnalysis::RISKS), 'risk_level');
        foreach (['required_facts', 'missing_facts'] as $field) {
            $output[$field] = $this->strings($output[$field] ?? null, $field);
        }
        $output['factory_capability_key'] = $this->nullableChoice($output['factory_capability_key'] ?? null, ProductQuestionAnalysis::FACTORY_CAPABILITIES, 'factory_capability_key');
        $output['factory_question'] = $this->nullableText($output['factory_question'] ?? null);
        $output['response_moves'] = $this->choices($output['response_moves'] ?? null, array_keys(ProductQuestionAnalysis::RESPONSE_MOVES), 'response_moves');
        $output['reply_pattern'] = $this->choice($output['reply_pattern'] ?? null, array_keys(ProductQuestionAnalysis::REPLY_PATTERNS), 'reply_pattern');
        $output['answer_summary'] = $this->nullableText($output['answer_summary'] ?? null);
        $output['automation_recommendation'] = $this->choice($output['automation_recommendation'] ?? null, array_keys(ProductQuestionAnalysis::AUTOMATION), 'automation_recommendation');
        if (! is_numeric($output['confidence'] ?? null) || (float) $output['confidence'] < 0 || (float) $output['confidence'] > 1) {
            throw new RuntimeException('confidence must be a number between 0 and 1.');
        }
        $output['confidence'] = round((float) $output['confidence'], 4);
        $output['analysis_summary'] = $this->text($output['analysis_summary'] ?? null, 'analysis_summary');
        $output['answer_assessment'] = $this->assessment($output['answer_assessment'] ?? null);

        if (! $hasAnswer && ($output['reply_pattern'] !== 'unanswered' || $output['answer_summary'] !== null)) {
            throw new RuntimeException('A record without an answer must use unanswered and a null answer_summary.');
        }
        if ($hasAnswer && ($output['reply_pattern'] === 'unanswered' || $output['answer_summary'] === null)) {
            throw new RuntimeException('A record with an answer cannot use unanswered or a null answer_summary.');
        }
        if ($output['automation_recommendation'] === 'auto_candidate' && (
            $output['risk_level'] !== 'low'
            || $output['answerability'] !== 'direct'
            || $output['work_queue'] !== 'catalog_answerable'
            || $output['missing_facts'] !== []
            || $output['answer_assessment']['policy_safety'] === 'poor'
            || $output['answer_assessment']['prohibited_or_risky_claims'] !== []
        )) {
            throw new RuntimeException('auto_candidate is allowed only for low-risk catalog-answerable direct answers without missing facts or risky claims.');
        }
        if ($output['work_queue'] === 'factory_review' && (
            $output['factory_capability_key'] === null || $output['factory_question'] === null
        )) {
            throw new RuntimeException('factory_review requires factory_capability_key and factory_question.');
        }
        if ($output['work_queue'] !== 'factory_review') {
            $output['factory_capability_key'] = null;
            $output['factory_question'] = null;
        }
        if ($output['work_queue'] === 'customer_clarification' && $output['answerability'] !== 'needs_clarification') {
            throw new RuntimeException('customer_clarification requires needs_clarification answerability.');
        }

        return $output;
    }

    /** @return array<string, mixed> */
    private function assessment(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('answer_assessment must be an object.');
        }
        foreach (['relevance', 'completeness', 'clarity', 'consistency', 'policy_safety'] as $field) {
            $value[$field] = $this->choice($value[$field] ?? null, self::ASSESSMENTS, "answer_assessment.{$field}");
        }
        foreach (['strengths', 'gaps', 'prohibited_or_risky_claims'] as $field) {
            $value[$field] = $this->strings($value[$field] ?? null, "answer_assessment.{$field}");
        }

        return $value;
    }

    private function choice(mixed $value, array $allowed, string $field): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new RuntimeException("{$field} contains an invalid value.");
        }

        return $value;
    }

    /** @return list<string> */
    private function choices(mixed $value, array $allowed, string $field): array
    {
        $items = $this->strings($value, $field);
        foreach ($items as $item) {
            if (! in_array($item, $allowed, true)) {
                throw new RuntimeException("{$field} contains an invalid value.");
            }
        }

        return $items;
    }

    /** @return list<string> */
    private function strings(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("{$field} must be a list.");
        }
        $items = [];
        foreach ($value as $item) {
            $items[] = $this->text($item, $field);
        }

        return array_values(array_unique($items));
    }

    private function text(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("{$field} must be a non-empty string.");
        }

        return trim($value);
    }

    private function nullableText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function nullableChoice(mixed $value, array $allowed, string $field): ?string
    {
        return $value === null || $value === '' ? null : $this->choice($value, $allowed, $field);
    }
}
