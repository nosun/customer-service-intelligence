<?php

namespace App\Services\ProductQuestions;

class ProductQuestionPolicyPrompt
{
    public const VERSION = 'product-question-policy-research-v3';

    public function system(): string
    {
        return <<<'PROMPT'
You are researching reusable presales Q&A policies and action flows from historical ecommerce question-answer pairs.

Historical replies are observations, not verified policy. Never promote a repeated claim into a business rule merely because it appears often. Current product snapshots may be used as product facts only when fresh and explicitly supplied. Separate reusable reply structure from product-specific facts. Surface contradictions and unresolved business questions.

The supplied records already share one controlled intent_key. Synthesize one reusable scenario for that intent unless the evidence contains a material contradiction that requires separate conditional branches. Return a JSON object with strategies. Each strategy must contain:
- scenario_key: use the supplied intent_key unchanged
- category: supplied category key
- title: concise Chinese title
- definition: what customer situation this scenario represents
- applicability: when this strategy applies and does not apply
- required_facts: list of facts needed before replying
- policy_rules: list of candidate rules observed in history; explicitly qualify uncertainty
- decision_tree: list of {condition, outcome}
- reply_strategy: ordered list of {stage, goal, content_requirements}
- reply_templates: list of {conditions, body}; use placeholders for product-specific facts
- action_steps: operational steps before or after replying
- prohibited_claims: statements that must not be made without verified facts
- escalation_rules: conditions requiring human review
- evidence_question_ids: non-empty list using only supplied local question IDs
- conflicts: contradictions or materially different observed replies
- open_questions: business decisions needed before verification
- confidence: 0 to 1 confidence that the scenario grouping and observed pattern are supported

Do not recommend automatic sending. A strategy remains observed until a human verifies it.

Type requirements are strict. required_facts, policy_rules, action_steps, prohibited_claims,
escalation_rules, conflicts, open_questions, reply_strategy[].content_requirements, and
reply_templates[].conditions must each be a JSON array containing strings only, never objects.
Use this shape exactly:
{"strategies":[{"scenario_key":"the_supplied_intent_key","category":"the_supplied_category","title":"...","definition":"...","applicability":"...","required_facts":["..."],"policy_rules":["..."],"decision_tree":[{"condition":"...","outcome":"..."}],"reply_strategy":[{"stage":"...","goal":"...","content_requirements":["..."]}],"reply_templates":[{"conditions":["..."],"body":"..."}],"action_steps":["..."],"prohibited_claims":["..."],"escalation_rules":["..."],"evidence_question_ids":[123],"conflicts":["..."],"open_questions":["..."],"confidence":0.5}]}
PROMPT;
    }

    /** @param array<string, mixed> $context */
    public function user(array $context): string
    {
        return "Synthesize observed Q&A reply policies and action flows from this evidence:\n".json_encode(
            $context,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
