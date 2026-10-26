<?php

namespace App\Services\TicketWork;

use Illuminate\Validation\ValidationException;

/** Validates the evidence-backed plan and selects templates before any customer prose is written. */
class TicketReplyPlan
{
    public const VERSION = 'ticket-investigation-v2';

    public const QUALITY_CHECKS = ['factual_accuracy', 'operator_decision', 'action_stage', 'template_applicability', 'intent_coverage', 'actionable_guidance', 'natural_tone', 'customer_appropriateness', 'language_equivalence'];

    public const STAGES = ['presales', 'in_sales', 'after_sales', 'undetermined'];

    public function validate(array $reply, array $runtime, ?array $guidance): array
    {
        $clauses = collect(data_get($runtime, 'plan.clauses', []))->keyBy('id');
        $facts = collect($runtime['facts'] ?? [])->keyBy('key');
        $policies = collect($runtime['policies'] ?? [])->keyBy('key');
        $messageIds = array_column([...data_get($runtime, 'ticket_context.messages', []), ...data_get($runtime, 'ticket_context.related_messages', [])], 'event_id');
        $units = $reply['answer_units'] ?? [];
        if (! is_array($units) || ! array_is_list($units) || count($units) !== $clauses->count()) {
            throw ValidationException::withMessages(['reply_plan' => 'Every request needs exactly one answer unit.']);
        }
        $seen = [];
        $issues = [];
        $followups = [];
        foreach ($units as &$unit) {
            if (! is_array($unit) || ! is_string($unit['request_id'] ?? null)
                || ! $clauses->has($unit['request_id']) || in_array($unit['request_id'], $seen, true)) {
                throw ValidationException::withMessages(['reply_plan' => 'Unknown or duplicate request ID.']);
            }
            $seen[] = $unit['request_id'];
            $intent = $clauses[$unit['request_id']]['intent'];
            $unit['intent'] = $intent;
            if ($intent === 'unsupported') {
                $issues[] = $unit['request_id'].': unsupported request requires human review.';
            }
            $stage = $unit['business_stage'] ?? 'undetermined';
            $phase = $unit['action_stage'] ?? 'information';
            if (! in_array($stage, self::STAGES, true)
                || ! in_array($phase, ['information', 'clarification', 'requested', 'decided', 'arranged', 'completed'], true)) {
                throw ValidationException::withMessages(['reply_plan' => 'Invalid business or action stage.']);
            }
            $unit['business_stage'] = $stage;
            $unit['action_stage'] = $phase;
            $business = $unit['business'] ?? $intent;
            $unit['business'] = $intent === 'refund_review' && $business === 'donation_refund' ? 'donation_refund' : $intent;
            if ($intent === 'refund_review' && $business === 'production_cancellation'
                && (($facts['order.shipping_status_code']['value'] ?? null) === 0 || ($facts['order.cancelled']['value'] ?? null) === true)) {
                // Evidence acquired after classification can distinguish an unshipped return request.
                $unit['business'] = 'production_cancellation';
            }

            foreach (['points', 'essential_unknowns', 'internal_followups'] as $key) {
                $unit[$key] = $this->textList($unit[$key] ?? [], $key);
            }
            foreach (['points', 'essential_unknowns', 'internal_followups', 'evidence_refs'] as $key) {
                if (! is_array($unit[$key] ?? null) || ! array_is_list($unit[$key])
                    || collect($unit[$key])->contains(fn ($v) => ! is_string($v) || trim($v) === '')) {
                    throw ValidationException::withMessages(['reply_plan' => "{$key} must be a list of nonempty strings."]);
                }
            }
            if (isset($unit['item_index']) && (! is_int($unit['item_index']) || $unit['item_index'] < 0)) {
                throw ValidationException::withMessages(['reply_plan' => 'Item index must be a nonnegative integer.']);
            }
            $quote = trim((string) ($unit['guidance_quote'] ?? ''));
            if ($quote !== '' && ($guidance === null || ! str_contains($guidance['text'], $quote))) {
                $issues[] = $unit['request_id'].': operator decision does not quote current guidance.';
            }
            foreach ($unit['evidence_refs'] as $ref) {
                [$type, $key] = array_pad(explode(':', $ref, 2), 2, '');
                if (! (($type === 'fact' && $facts->has($key)) || ($type === 'policy' && $policies->has($key))
                    || ($type === 'event' && ctype_digit($key) && in_array((int) $key, $messageIds, true))
                    || ($type === 'guidance' && $key === 'current' && $quote !== '' && $guidance !== null && str_contains($guidance['text'], $quote)))) {
                    $issues[] = $unit['request_id'].': unknown or out-of-scope evidence '.$ref;
                }
            }
            if ($unit['points'] !== [] && $unit['evidence_refs'] === [] && $phase !== 'clarification') {
                $issues[] = $unit['request_id'].': reply points lack evidence.';
            }
            if ($intent === 'presales_consultation' && $stage !== 'presales'
                || in_array($intent, ['production_cancellation', 'standard_size_change', 'production_order_status'], true) && $stage !== 'in_sales'
                || $intent === 'after_sales_complaint' && $stage !== 'after_sales') {
                $issues[] = $unit['request_id'].': business stage does not match the request.';
            }
            if (in_array($phase, ['decided', 'arranged'], true) && $quote === '') {
                $issues[] = $unit['request_id'].': planned or arranged action requires an explicit operator statement.';
            }
            if ($phase === 'completed') {
                $supported = match ($unit['business']) {
                    'production_cancellation' => ($facts['order.cancelled']['value'] ?? null) === true,
                    'standard_size_change' => $quote !== '' && isset($unit['item_index'], $unit['target_size'], $unit['original_size'])
                        && ($facts['order.item_sizes']['value'][$unit['item_index']]['size'] ?? null) === $unit['target_size']
                        && str_contains($quote, (string) $unit['target_size']) && str_contains($quote, (string) $unit['original_size']),
                    // Merchant/gateway completion requires separate evidence, not an internal refund record.
                    default => false,
                };
                if (! $supported) {
                    $issues[] = $unit['request_id'].': completed action lacks a verified result.';
                }
            }
            if (in_array($intent, ['production_cancellation', 'standard_size_change', 'refund_review'], true)
                && ($facts['order.identity_match']['value'] ?? null) !== true) {
                $issues[] = $unit['request_id'].': order identity has not been verified.';
            }
            $unit['template'] = $this->template($unit, $policies->values()->all());
            $unit['template_gap'] = $unit['template'] === null;
            $unit['process_refs'] = $policies->filter(fn ($p) => ($p['knowledge_type'] ?? '') === 'process'
                && (data_get($p, 'reply_strategy.0.intent') === $unit['business'] || in_array($unit['business'], $p['bound_intents'] ?? [], true)))
                ->map(fn ($p) => ['key' => $p['key'], 'version' => $p['version'], 'steps' => $p['action_steps'] ?? []])->values()->all();
            if ($unit['essential_unknowns'] !== []) {
                // A missing fact belongs to this answer unit; it does not erase other confirmed answers.
                $followups = [...$followups, ...$unit['essential_unknowns']];
            }
            $followups = [...$followups, ...$unit['internal_followups']];
        }
        unset($unit);

        if (isset($reply['investigation_requests']) && (! is_array($reply['investigation_requests']) || collect($reply['investigation_requests'])->contains(fn ($v) => ! is_string($v)))) {
            throw ValidationException::withMessages(['reply_plan' => 'Investigation requests must be a list of supported tool names.']);
        }

        return [
            ...array_intersect_key($reply, array_flip(['response_decision', 'case_progress', 'history_incomplete'])),
            'version' => self::VERSION,
            'answer_units' => $units,
            'internal_followups' => array_values(array_unique($followups)),
            'blocking_reasons' => array_values(array_unique($issues)),
            'investigation_requests' => array_values(array_intersect((array) ($reply['investigation_requests'] ?? []), ['refresh_order'])),
        ];
    }

    private function textList(mixed $items, string $field): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            throw ValidationException::withMessages(['reply_plan' => $field.' must be a list.']);
        }
        $keys = match ($field) {
            'points' => ['text', 'reply_point', 'statement'],
            'essential_unknowns' => ['text', 'question', 'description'],
            'internal_followups' => ['text', 'task', 'action', 'description'],
        };

        return array_map(function ($item) use ($keys, $field): string {
            if (is_string($item) && trim($item) !== '') {
                return trim($item);
            }
            if (is_array($item)) {
                foreach ($keys as $key) {
                    if (is_string($item[$key] ?? null) && trim($item[$key]) !== '') {
                        return trim($item[$key]);
                    }
                }
            }
            throw ValidationException::withMessages(['reply_plan' => $field.' contains an unrecognized evidence item.']);
        }, $items);
    }

    private function template(array $unit, array $policies): ?array
    {
        $candidates = [];
        foreach ($policies as $policy) {
            foreach ($policy['reply_templates'] ?? [] as $template) {
                if (! is_array($template) || ($template['business'] ?? '') !== $unit['business']
                    || ($template['business_stage'] ?? '') !== $unit['business_stage']
                    || ($template['action_stage'] ?? '') !== $unit['action_stage']
                    || ! is_string($template['id'] ?? null) || ! is_string($template['body'] ?? null)) {
                    continue;
                }
                if (collect($template['conditions'] ?? [])->contains(fn ($value, $key) => ($unit[$key] ?? null) !== $value)) {
                    continue;
                }
                $variables = [];
                $usable = true;
                foreach ($template['variables'] ?? [] as $variable) {
                    // Only explicitly supported unit fields can become template variables.
                    if (! in_array($variable, ['original_size', 'target_size'], true) || ! is_string($unit[$variable] ?? null)
                        || ! preg_match('/\A\d{1,2}W?\z/', $unit[$variable])) {
                        $usable = false;
                        break;
                    }
                    $variables[$variable] = $unit[$variable];
                }
                if (! $usable) {
                    continue;
                }
                $body = $template['body'];
                foreach ($variables as $key => $value) {
                    $body = str_replace('{{'.$key.'}}', $value, $body);
                }
                if (str_contains($body, '{{')) {
                    continue;
                }
                $candidates[] = [...$template, 'body' => $body, 'variables' => $variables,
                    'article_key' => $policy['key'], 'article_version' => $policy['version'], 'site_key' => $policy['site_key'] ?? null];
            }
        }
        $scoped = array_values(array_filter($candidates, fn ($c) => $c['site_key'] !== null));
        $candidates = $scoped !== [] ? $scoped : $candidates;

        // Conflicting matching templates become a visible gap instead of an arbitrary choice.
        return count($candidates) === 1 ? $candidates[0] : null;
    }
}
