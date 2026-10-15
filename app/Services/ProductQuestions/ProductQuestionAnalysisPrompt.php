<?php

namespace App\Services\ProductQuestions;

use App\Models\ProductQuestionAnalysis;

class ProductQuestionAnalysisPrompt
{
    public const VERSION = 'product-question-analysis-v2';

    public const BATCH_VERSION = 'product-question-routing-batch-v1';

    public function system(): string
    {
        return <<<'PROMPT'
You analyze historical presales product Q&A for an ecommerce customer-service team.

Treat the historical answer as observed behavior, never as verified policy. Do not invent product facts, policy, outcomes, or customer intent. Keep observed facts separate from missing facts. Assess the answer that was actually given, including whether it is missing, incomplete, risky, or overly certain.

Return one JSON object with exactly these fields:
- primary_category: one allowed category key
- secondary_categories: zero or more allowed category keys, excluding primary_category
- intent_key: one allowed intent key; choose the nearest broad reusable intent and use unclear_or_multi_intent when no single intent fits
- customer_intent: concise Chinese summary
- question_scope: one of product_specific, site_policy, mixed, unknown
- answer_source: one of product_catalog, verified_policy, product_and_policy, factory_capability, customer_input, human_judgment, unknown
- work_queue: one of catalog_answerable, catalog_data_missing, policy_review, factory_review, customer_clarification, human_review
- answerability: one of direct, needs_business_facts, needs_clarification, escalate
- risk_level: one of low, medium, high
- required_facts: list of facts required for a reliable reply
- missing_facts: list of required facts not established by the supplied record
- factory_capability_key: one allowed factory capability when a reusable factory decision is needed, otherwise null
- factory_question: concise Chinese question that can establish a reusable rule including applicability, exceptions, fee and lead-time impact, otherwise null
- response_moves: ordered list using only acknowledge, direct_conclusion, provide_facts, state_conditions, set_expectation, request_information, give_steps, recommend_alternative, state_limit, escalate
- reply_pattern: one of direct_answer, conditional_answer, clarification_request, recommendation, refusal_boundary, escalation, incomplete, unanswered
- answer_summary: concise Chinese summary, or null when unanswered
- answer_assessment: object with relevance, completeness, clarity, consistency, policy_safety (each good, partial, poor, or not_applicable), strengths (list), gaps (list), prohibited_or_risky_claims (list)
- automation_recommendation: one of auto_candidate, human_review, draft_only, never_auto
- confidence: number between 0 and 1
- analysis_summary: concise Chinese conclusion explaining the classification, historical reply pattern, and automation boundary

Only use auto_candidate when risk is low, the question is repeatable, and a future reply can be grounded in verified knowledge or fresh business facts. Missing or unverified facts must not be converted into claims.

Routing rules:
- Use catalog_answerable only when current product_catalog contains the facts needed to answer safely. auto_candidate is only allowed in this queue.
- Use catalog_data_missing when the customer asks for a clear product fact or additional product photo that should come from the merchant/catalog, but it is not present in current product_catalog.
- Use policy_review when a reusable site policy is needed or an observed historical claim still needs business verification.
- Use factory_review when manufacturing capability, customization feasibility, fee, exceptions, or production-time impact needs factory confirmation. Provide factory_capability_key and factory_question.
- Use customer_clarification only when the customer must supply missing information such as measurements, destination, event date, or desired option. Never use it when the customer is asking the merchant to provide product facts, photos, or documents.
- Use human_review for ambiguous, conflicting, exceptional, or high-risk cases.
- Historical answers are never a verified_policy source by themselves.
PROMPT;
    }

    /** @param array<string, mixed> $context */
    public function user(array $context): string
    {
        $context['allowed_categories'] = array_keys(ProductQuestionAnalysis::CATEGORIES);
        $context['allowed_intents'] = ProductQuestionAnalysis::INTENTS;
        $context['allowed_factory_capabilities'] = ProductQuestionAnalysis::FACTORY_CAPABILITIES;

        return "Analyze this historical product Q&A record:\n".json_encode(
            $context,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /** @param list<array<string, mixed>> $contexts */
    public function batchUser(array $contexts): string
    {
        return "Analyze every historical product Q&A record independently. Return exactly one item for every supplied record_id:\n".json_encode(
            [
                'allowed_categories' => array_keys(ProductQuestionAnalysis::CATEGORIES),
                'allowed_intents' => ProductQuestionAnalysis::INTENTS,
                'allowed_factory_capabilities' => ProductQuestionAnalysis::FACTORY_CAPABILITIES,
                'records' => collect($contexts)->map(fn (array $context): array => [
                    'record_id' => $context['record_id'],
                    'product_sn' => $context['product_sn'],
                    'product_name' => data_get($context, 'product.name'),
                    'question' => $context['question'],
                    'observed_answer' => $context['answer'],
                    'has_current_product_catalog' => $context['product_catalog'] !== null,
                    'current_catalog_facts' => $context['product_catalog'] === null ? null : [
                        'attribute_keys' => array_keys($context['product_catalog']['attributes'] ?? []),
                        'color_count' => count($context['product_catalog']['colors'] ?? []),
                        'size_count' => count($context['product_catalog']['sizes'] ?? []),
                        'image_count' => count($context['product_catalog']['images'] ?? []),
                        'additional_service_count' => count($context['product_catalog']['additional_services'] ?? []),
                    ],
                ])->all(),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    public function batchSystem(): string
    {
        return <<<'PROMPT'
You route historical ecommerce presales questions into reusable intent and operational work queues.

Analyze each record independently. The historical answer is observed behavior, never verified policy. Do not transfer facts between products and do not invent product facts, policy, fees, lead times, or factory capabilities.

Return one JSON object with exactly one top-level field named items. Return exactly one item for every supplied record_id. Each item must contain:
- record_id: supplied numeric record ID
- primary_category: one supplied category key
- secondary_categories: zero or more supplied category keys, excluding primary_category
- intent_key: one supplied allowed_intents value; choose the nearest broad reusable intent and use unclear_or_multi_intent when no single intent fits
- customer_intent: concise Chinese intent summary
- question_scope: product_specific, site_policy, mixed, or unknown
- answer_source: product_catalog, verified_policy, product_and_policy, factory_capability, customer_input, human_judgment, or unknown
- work_queue: catalog_answerable, catalog_data_missing, policy_review, factory_review, customer_clarification, or human_review
- risk_level: low, medium, or high
- required_facts: concise list of facts required for a reliable reply
- missing_facts: facts not established by the supplied record; an observed answer does not establish policy
- factory_capability_key: one supplied allowed_factory_capabilities value only for factory_review, otherwise null
- factory_question: concise Chinese question covering applicability, exceptions, fee, and lead-time impact only for factory_review, otherwise null
- confidence: 0 to 1 confidence in intent and routing

Routing rules:
- catalog_answerable requires answer_source=product_catalog and current_catalog_facts showing the needed fact is available.
- catalog_data_missing requires answer_source=product_catalog. Use it when the question is clear and the merchant must supply a missing product fact or additional product photo. This is not customer_clarification.
- policy_review requires answer_source=verified_policy or product_and_policy. Historical repetition never makes a policy verified.
- factory_review requires answer_source=factory_capability plus factory_capability_key and factory_question. Prefer one reusable factory capability over an SN-specific question.
- customer_clarification requires answer_source=customer_input and is only for information the customer must provide, such as measurements, destination, event date, or desired option. Never use it because the merchant owes the customer product details, photos, or documents.
- human_review covers ambiguous, conflicting, exceptional, multi-intent, or high-risk cases.
- When one question contains two independent requests (for example product photos plus delivery time, or exchange policy plus size advice), use intent_key=unclear_or_multi_intent and work_queue=human_review instead of choosing only one request.
- Use request_custom_color only when the customer asks to manufacture a nonstandard color or change part of the design. If the customer selects an advertised color and asks how another component will look, use ask_color_match.
- Do not omit, merge, duplicate, or invent record_id values.
PROMPT;
    }
}
