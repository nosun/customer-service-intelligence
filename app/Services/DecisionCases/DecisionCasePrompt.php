<?php

namespace App\Services\DecisionCases;

use JsonException;

class DecisionCasePrompt
{
    public const VERSION = 'decision-case-prompt-v1';

    public function system(): string
    {
        return <<<'PROMPT'
You analyze historical ecommerce customer-service tickets and convert each ticket into one auditable decision case.

Read the entire ticket in chronological order. Identify distinct decision nodes. A decision node is a customer request followed by investigation, a decision, an action, an outcome, or a material change in the request. Merge adjacent turns when they concern the same unresolved decision. Create a new node when the request changes, the previous decision is challenged, or handling is escalated.

Evidence rules:
- Use only facts explicitly present in the supplied ticket.
- Every material fact, request, decision, reason, action, outcome, and exception must cite one or more supplied integer event_id values.
- Every node must contain at least one evidence object with role "request" that references a message whose author_type is "client".
- Do not infer that an operation actually happened merely because an agent promised it.
- Preserve the distinction between customer claims, agent statements, and observable outcomes.
- If a reason, action, or outcome is absent, return null.
- Put unresolved contradictions and weak inferences in uncertainties.
- Historical handling is observed behavior, not approved company policy.
- Never invent an order state, refund amount, deadline, policy, approval, or customer acceptance.

Write summaries in concise Chinese. Keep enum values and field names exactly as specified. Return one JSON object only, without Markdown or commentary.

Required JSON shape:
{
  "case_summary": "string or null",
  "primary_category": "presales|production|logistics|after_sales|unknown",
  "primary_problem_type": "string or null",
  "case_risk": "normal|high|critical",
  "nodes": [
    {
      "sequence": 1,
      "problem_type": "string or null",
      "scenario_key": "short_snake_case string or null",
      "request_summary": "non-empty string",
      "observed_facts": [
        {"name": "string", "value": "string", "evidence_event_ids": [1]}
      ],
      "missing_facts": ["string"],
      "observed_decision": "accepted|rejected|conditional|alternative|escalated|unknown",
      "observed_action": "string or null",
      "stated_reason": "string or null",
      "observed_outcome": "string or null",
      "is_exception": false,
      "evidence": [
        {"event_id": 1, "role": "request|fact|decision|reason|action|outcome|exception"}
      ],
      "uncertainties": ["string"],
      "extraction_confidence": 0.0
    }
  ]
}

If there is no public customer request, return an empty nodes array and explain this in case_summary.
PROMPT;
    }

    /** @param array<string, mixed> $context */
    public function user(array $context): string
    {
        try {
            return "Analyze this ticket and return the required JSON object:\n".json_encode(
                $context,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode the decision-case context.', previous: $exception);
        }
    }
}
