<?php

namespace App\Services\ProductQuestions;

use App\Models\ProductQuestionAnalysis;

class ProductQuestionAnalysisPrompt
{
    public const VERSION = 'product-question-analysis-v9';

    public const BATCH_VERSION = 'product-question-routing-batch-v9';

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
- Use ask_dress_length when the customer asks for a dress length such as shoulder-to-hem or hollow-to-hem. The answer must use the current product hemline, requested standard size, and the verified hollow-to-hem reference chart. Do not describe hollow-to-hem as shoulder-to-hem.
- Use ask_pockets when the customer only asks whether the current product already has pockets. Use request_add_pockets when the customer asks to add or customize pockets, including "does it have pockets, or can they be added?" Historical answers about other products cannot establish the current product's default pocket construction. Add-pocket eligibility must be decided from the current product silhouette; Mermaid/Trumpet is the verified exception.
- Use website_usage with request_custom_size when the customer asks where or how to enter measurements on the product page. This is an operational website question, not a request to recommend a size. Use size_fit with ask_size_recommendation only when the customer asks which size fits or supplies measurements for comparison.
- For product_catalog.type=dress, customizable=1, custom_size_input_available=true, and custom_size_entry_method=size_selector, treat the Custom Size workflow as a verified current capability: Size -> Custom Size -> complete the displayed measurement fields. The customer does not need to supply measurements, confirm a shopping stage, or ask about fees before this workflow can be answered.
- Use catalog_answerable when current product_catalog plus the supplied verified workflow contains the facts needed to answer safely. answer_source may be product_catalog or product_and_policy. auto_candidate is only allowed in this queue, but sending still requires the application's human Preview and Confirm workflow.
- Do not add unasked facts about Custom Size fees, rush service, production impact, or customer-service contact to required_facts or missing_facts. If the customer also gives an event date, delivery feasibility needs the destination country and fresh ETA, but this does not block the direct Custom Size instructions.
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
                        'type' => $context['product_catalog']['type'] ?? null,
                        'customizable' => $context['product_catalog']['customizable'] ?? null,
                        'editable_fields' => $context['product_catalog']['editable_fields'] ?? [],
                        'custom_size_input_available' => $context['product_catalog']['custom_size_input_available'] ?? null,
                        'custom_size_entry_method' => $context['product_catalog']['custom_size_entry_method'] ?? null,
                        'size_chart_available' => $context['product_catalog']['size_chart_available'] ?? false,
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
- Use ask_dress_length when the customer asks for a dress length such as shoulder-to-hem or hollow-to-hem. This is catalog_answerable only when the current product hemline and requested size can be resolved through the verified hollow-to-hem chart.
- Use ask_pockets when the customer only asks whether the current product already has pockets. Use request_add_pockets when the customer asks to add or customize pockets, including "does it have pockets, or can they be added?" Historical answers about other products cannot establish the current product's default pocket construction. Add-pocket eligibility must be decided from the current product silhouette; Mermaid/Trumpet is the verified exception.
- Use website_usage with request_custom_size when the customer asks where or how to enter measurements on the product page. This is an operational website question, not a size recommendation. Use size_fit with ask_size_recommendation only when the customer asks which size fits or supplies measurements for comparison.
- When current_catalog_facts show type=dress, customizable=1, custom_size_input_available=true, and custom_size_entry_method=size_selector, route the measurement-entry workflow to catalog_answerable with answer_source=product_and_policy. Do not route it to factory_review and do not require the customer's measurements, shopping stage, Custom Size fee, or rush-service facts.
- catalog_answerable requires current_catalog_facts showing the needed fact is available. Its answer_source may be product_catalog or product_and_policy.
- catalog_data_missing requires answer_source=product_catalog. Use it when the question is clear and the merchant must supply a missing product fact or additional product photo. This is not customer_clarification.
- policy_review requires answer_source=verified_policy or product_and_policy. Historical repetition never makes a policy verified.
- factory_review requires answer_source=factory_capability plus factory_capability_key and factory_question. Prefer one reusable factory capability over an SN-specific question.
- customer_clarification requires answer_source=customer_input and is only for information the customer must provide, such as measurements, destination, event date, or desired option. Never use it because the merchant owes the customer product details, photos, or documents.
- human_review covers ambiguous, conflicting, exceptional, multi-intent, or high-risk cases.
- When one question contains two independent requests (for example product photos plus delivery time, or exchange policy plus size advice), use intent_key=unclear_or_multi_intent and work_queue=human_review instead of choosing only one request.
- Use ask_product_origin only for where the product is made or manufactured.
- Use ask_production_time for manufacturing or processing lead time without destination-specific total delivery evaluation.
- Use ask_delivery_time for the standard destination-specific arrival estimate or whether standard delivery can meet an event date.
- Use request_rush_order only when the customer explicitly asks for rush, expedited production, fast dispatch, or a faster-than-standard option. A close event date alone remains ask_delivery_time unless expedited service is requested.
- Use ask_shipping_options for destination support, shipping methods, post-dispatch transit time, estimated shipping cost, or import duty. Do not use it for total arrival ETA, production time, or an existing order's tracking status.
- Use ask_return_policy for return eligibility, return conditions, exchanges, or changing size after purchase. The site does not offer direct exchanges, so exchange and size-change questions belong to the same return-policy intent.
- Use ask_refund only for an actual after-sales refund, compensation, quality, wrong-item, or other complaint that requires order-specific review. Do not use it for a general presales question about the refundable amount under the return policy.
- Use request_custom_color only when the customer asks to manufacture a nonstandard color or change part of the design. If the customer selects an advertised color and asks how another component will look, use ask_color_match.
- Do not omit, merge, duplicate, or invent record_id values.
PROMPT;
    }
}
