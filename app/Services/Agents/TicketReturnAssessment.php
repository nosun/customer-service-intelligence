<?php

namespace App\Services\Agents;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Stage transitions are separate from missing offer data and do not execute a return approval. */
class TicketReturnAssessment
{
    public const CONDITIONS = ['receipt_date', 'request_date', 'item_condition', 'security_seal', 'item_eligibility', 'request_window'];

    public const POSITIONS = ['initial', 'conditions_confirmed', 'conditional_acceptance', 'option_accepted', 'return_shipped', 'return_received', 'refund_issued', 'none'];

    public const STAGES = ['collect_conditions', 'offer_options', 'send_instructions', 'track_return', 'review_refund', 'exception_review'];

    public function playbook(): array
    {
        $definition = json_decode(file_get_contents(resource_path('data/ticket-return-playbook-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->validateDefinition($definition);

        return $definition;
    }

    public function validateDefinition(array $definition): void
    {
        Validator::make($definition, [
            'format_version' => 'required|in:1.0', 'workflow_key' => 'required|in:return',
            'version' => ['required', 'regex:/^\d+\.\d+\.\d+$/'], 'owner' => 'required|string', 'lifecycle' => 'required|in:local_active',
            'scope' => 'required|string', 'sources' => 'required|array|min:1',
            'sources.*.id' => 'required|string|distinct', 'sources.*.kind' => 'required|in:policy,reference,case',
            'sources.*.location' => 'required|string', 'sources.*.authority' => 'required|string',
            'interpretation_rules' => 'required|array|min:1', 'interpretation_rules.*' => 'required|string',
            'capabilities' => 'required|array|min:1', 'capabilities.*.status' => 'required|in:available,partial,manual,unavailable',
            'capabilities.*.tools' => 'required|array|min:1', 'capabilities.*.tools.*' => 'required|string',
            'capabilities.*.owner' => 'required|string', 'capabilities.*.gap' => 'required|string',
            'routing' => 'required|array|min:1', 'routing.*.id' => 'required|string|distinct',
            'routing.*.when' => 'required|array', 'routing.*.stage' => 'required|string',
            'edges' => 'required|array|min:1', 'edges.*.from' => 'required|string', 'edges.*.to' => 'required|string', 'edges.*.event' => 'required|string',
            'stages' => 'required|array|size:6', 'stages.*.template_id' => 'required|string|distinct',
            'stages.*.label_zh' => 'required|string', 'stages.*.goal' => 'required|string', 'stages.*.template' => 'required|string',
            'stages.*.requirements' => 'required|array|min:1', 'stages.*.requirements.*' => 'required|string',
            'stages.*.entry' => 'required|array|min:1', 'stages.*.entry.*' => 'required|string',
            'stages.*.required_evidence' => 'required|array|min:1', 'stages.*.optional_evidence' => 'present|array',
            'stages.*.required_evidence.*' => 'required|string', 'stages.*.optional_evidence.*' => 'required|string',
            'stages.*.completion.analysis' => 'required|string', 'stages.*.completion.draft' => 'required|string', 'stages.*.completion.business' => 'required|string',
            'stages.*.exceptions' => 'required|array|min:1', 'stages.*.exceptions.*.when' => 'required|string',
            'stages.*.exceptions.*.owner' => 'required|string', 'stages.*.exceptions.*.action' => 'required|string',
            'stages.*.evaluation_cases' => 'required|array|min:1', 'stages.*.evaluation_cases.*' => 'required|string',
            'maintenance.definition_source' => 'required|string', 'maintenance.routing_cases' => 'required|string', 'maintenance.semantic_cases' => 'required|string',
            'maintenance.change_policy' => 'required|string', 'maintenance.feedback_policy' => 'required|string',
            'maintenance.metrics' => 'required|array|min:1', 'maintenance.release_requirements' => 'required|array|min:1',
        ])->validate();
        $stages = array_keys($definition['stages']);
        if (array_diff(self::STAGES, $stages) !== [] || array_diff($stages, self::STAGES) !== []) {
            throw new RuntimeException('Return node keys must match the supported runtime stages.');
        }
        $tools = ['read_conversation', 'read_policy', 'read_site_return_policy', 'read_order', 'list_documents', 'inspect_document', 'read_return_status'];
        foreach ($definition['capabilities'] as $capability) {
            if (array_diff($capability['tools'], $tools) !== []) {
                throw new RuntimeException('Return capability references an unknown tool.');
            }
        }
        foreach ($definition['stages'] as $node) {
            if (array_diff([...$node['required_evidence'], ...$node['optional_evidence']], array_keys($definition['capabilities'])) !== []) {
                throw new RuntimeException('Return node references an unknown evidence capability.');
            }
        }
        foreach ($definition['edges'] as $edge) {
            if (array_diff([$edge['from'], $edge['to']], $stages) !== []) {
                throw new RuntimeException('Return edge references an unknown node.');
            }
        }
        if (array_diff($stages, array_column($definition['routing'], 'stage')) !== []
            || array_diff(array_column($definition['routing'], 'stage'), $stages) !== []) {
            throw new RuntimeException('Every return node must have a valid route.');
        }
        foreach ($definition['routing'] as $index => $rule) {
            $when = $rule['when'];
            if (array_diff(array_keys($when), ['always', 'positions', 'condition_statuses', 'exception_present']) !== []
                || (isset($when['positions']) && (! is_array($when['positions']) || $when['positions'] === [] || array_diff($when['positions'], array_diff(self::POSITIONS, ['none'])) !== []))
                || (isset($when['condition_statuses']) && (! is_array($when['condition_statuses']) || $when['condition_statuses'] === [] || array_diff($when['condition_statuses'], ['satisfied', 'missing', 'conflict', 'failed', 'not_applicable']) !== []))
                || (isset($when['exception_present']) && $when['exception_present'] !== true)
                || (array_key_exists('always', $when) ? ($when !== ['always' => true] || $index !== array_key_last($definition['routing'])) : $index === array_key_last($definition['routing']))) {
                throw new RuntimeException('Invalid return route guard or fallback priority.');
            }
        }
    }

    public function agentPlaybook(): array
    {
        $definition = $this->playbook();
        unset($definition['maintenance']);
        foreach ($definition['stages'] as &$node) {
            unset($node['evaluation_cases']);
        }
        unset($node);

        return $definition;
    }

    public function record(array $input, array $refs): array
    {
        $playbook = $this->playbook();
        Validator::make($input, [
            'applicable' => 'required|boolean', 'reason' => 'required|string|max:3000',
            'evidence_refs' => 'required|array|min:1|max:30', 'evidence_refs.*' => 'required|string',
            'conditions' => 'present|array|max:6', 'conditions.*.key' => 'required|in:'.implode(',', self::CONDITIONS),
            'conditions.*.status' => 'required|in:satisfied,missing,conflict,failed,not_applicable',
            'conditions.*.explanation' => 'required|string|max:3000',
            'conditions.*.evidence_refs' => 'present|array|max:30', 'conditions.*.evidence_refs.*' => 'required|string',
            'customer_position' => 'required|in:initial,conditions_confirmed,conditional_acceptance,option_accepted,return_shipped,return_received,refund_issued,none',
            'position_evidence_refs' => 'present|array|max:30', 'position_evidence_refs.*' => 'required|string',
            'exception_reason' => 'present|string|max:3000',
        ])->validate();
        foreach ([$input, ...$input['conditions'], ['evidence_refs' => $input['position_evidence_refs']]] as $claim) {
            if (array_diff($claim['evidence_refs'], $refs) !== [] || (in_array($claim['status'] ?? '', ['satisfied', 'failed', 'conflict'], true) && $claim['evidence_refs'] === [])) {
                throw new RuntimeException('Return assessment must use retrieved evidence.');
            }
            if (($claim['status'] ?? '') === 'satisfied') {
                $prefixes = $claim['key'] === 'request_window' ? ['site_policy:', 'policy:', 'guidance:'] : ['event:', 'fact:', 'guidance:'];
                if (! collect($claim['evidence_refs'])->contains(fn ($ref) => str_starts_with($ref, $prefixes[0])
                    || str_starts_with($ref, $prefixes[1]) || str_starts_with($ref, $prefixes[2]))) {
                    throw new RuntimeException('A strategy or template is not proof of a satisfied return condition.');
                }
            }
        }
        if (! $input['applicable']) {
            if ($input['conditions'] !== [] || $input['customer_position'] !== 'none') {
                throw new RuntimeException('Non-return assessment cannot include return conditions or progress.');
            }

            return [...$input, 'stage' => null, 'template_id' => null, 'routing_rule' => null];
        }
        $keys = array_column($input['conditions'], 'key');
        if ($input['customer_position'] === 'none') {
            throw new RuntimeException('An applicable return must identify the customer position.');
        }
        if (count($keys) !== count(self::CONDITIONS) || count(array_unique($keys)) !== count($keys)) {
            throw new RuntimeException('Return assessment must cover every condition exactly once.');
        }
        if ($input['customer_position'] !== 'initial' && ! collect($input['position_evidence_refs'])->contains(fn ($ref) => str_starts_with($ref, 'event:'))) {
            throw new RuntimeException('Return progress requires cited conversation evidence.');
        }
        $statuses = array_column($input['conditions'], 'status');
        $route = collect($playbook['routing'])->first(fn ($rule) => ($rule['when']['always'] ?? false)
            || in_array($input['customer_position'], $rule['when']['positions'] ?? [], true)
            || (($rule['when']['exception_present'] ?? false) && $input['exception_reason'] !== '')
            || array_intersect($statuses, $rule['when']['condition_statuses'] ?? []) !== []);
        $stage = $route['stage'];
        $definition = $playbook['stages'][$stage];
        $advancedConflict = in_array($stage, ['send_instructions', 'track_return', 'review_refund'], true)
            && ($input['exception_reason'] !== '' || array_intersect($statuses, ['conflict', 'failed']) !== []);

        return [...$input, 'stage' => $stage, 'template_id' => $definition['template_id'],
            'reply_requirements' => $definition['requirements'], 'strategy_version' => $playbook['version'],
            'definition_hash' => hash_file('sha256', resource_path('data/ticket-return-playbook-v1.json')),
            'routing_rule' => $route['id'], 'requires_internal_review' => $advancedConflict,
            'node_contract' => array_intersect_key($definition, array_flip(['entry', 'required_evidence', 'optional_evidence', 'completion', 'exceptions']))];
    }

    public function recordForTool(array $input, array $refs): array
    {
        $this->playbook(); // Definition faults are application errors, not correctable model input.
        try {
            return ['assessment' => $this->record($input, $refs), 'evidence_refs' => ['assessment:return']];
        } catch (RuntimeException|ValidationException $exception) {
            return ['status' => 'invalid_assessment', 'error' => $exception->getMessage(),
                'correction' => 'Correct the assessment using retrieved evidence. If applicable=true, include all six distinct condition keys exactly once, even after acceptance/dispatch; unknown historical conditions use missing with an explanation. Preserve the evidenced advanced customer position. Invalid input records no assessment and cannot select a template.',
                'evidence_refs' => []];
        }
    }
}
