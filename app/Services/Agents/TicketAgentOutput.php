<?php

namespace App\Services\Agents;

use App\Services\TicketWork\TicketResponseAssessment;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class TicketAgentOutput
{
    public const CHECKS = ['factual_accuracy', 'operator_decision', 'action_stage', 'template_applicability', 'intent_coverage', 'actionable_guidance', 'natural_tone', 'customer_appropriateness', 'language_equivalence', 'stage_transition', 'investigation_completion'];

    public function validate(array $report, TicketAgentTools $tools): array
    {
        Validator::make($report, [
            'status' => 'required|in:needs_review,blocked',
            'plan' => 'required|array', 'candidate' => 'required|array',
            'candidate.answer_en' => 'present|string|max:12000',
            'candidate.answer_customer_language' => 'present|string|max:12000',
            'candidate.answer_zh' => 'present|string|max:12000',
            'candidate.answer_units' => 'present|array|max:12',
            'candidate.conclusions' => 'required|array|min:1|max:20',
            'reviews' => 'required|array|min:1|max:2',
        ])->validate();
        $candidate = $report['candidate'];
        if ($tools->plan === null || $report['plan'] !== $tools->plan) {
            throw new RuntimeException('Agent report plan does not match the recorded plan.');
        }
        $ids = array_column($tools->plan['clauses'], 'id');
        $covered = array_column($candidate['answer_units'], 'request_id');
        if (count($covered) !== count($ids) || count(array_unique($covered)) !== count($ids) || array_diff($ids, $covered) !== []) {
            throw new RuntimeException('Agent answer request coverage is incomplete.');
        }
        $refs = $tools->evidenceRefs();
        foreach ([...$candidate['conclusions'], ...$candidate['answer_units']] as $claim) {
            $claimRefs = $claim['evidence_refs'] ?? [];
            if (! is_array($claimRefs) || $claimRefs === [] || array_diff($claimRefs, $refs) !== []) {
                throw new RuntimeException('Agent cited evidence that was not read.');
            }
        }
        $context = collect($tools->ledger)->firstWhere('name', 'read_conversation')['result'] ?? null;
        if (! is_array($context)) {
            throw new RuntimeException('Agent did not read the conversation.');
        }
        $reply = app(TicketResponseAssessment::class)->validate($candidate, ['plan' => $tools->plan], $context);
        $emails = [$candidate['answer_en'], $candidate['answer_customer_language'], $candidate['answer_zh']];
        $noReply = $reply['response_decision']['mode'] === 'no_reply';
        if ($noReply ? collect($emails)->contains(fn ($s) => trim($s) !== '') : collect($emails)->contains(fn ($s) => trim($s) === '')) {
            throw new RuntimeException('Agent response decision and email presence disagree.');
        }
        $review = $report['reviews'][array_key_last($report['reviews'])];
        $hash = $this->candidateHash($candidate);
        if (! hash_equals($hash, (string) ($review['candidate_hash'] ?? ''))) {
            throw new RuntimeException('Independent review does not cover the final emails.');
        }
        $blocked = [];
        $return = $tools->returnAssessment;
        $returnReply = $candidate['return_reply'] ?? null;
        if ($return === null && array_intersect(['refund_review', 'after_sales_complaint'], $tools->plan['intents']) !== []) {
            $blocked[] = 'Return applicability and stage were not assessed.';
        }
        if (($return['applicable'] ?? false) && ! $noReply) {
            $template = collect($tools->ledger)->where('name', 'read_return_template')->last()['result'] ?? [];
            if (! is_array($returnReply) || ($returnReply['stage'] ?? null) !== $return['stage']
                || ($returnReply['template_id'] ?? null) !== $return['template_id'] || ($template['template_id'] ?? null) !== $return['template_id']) {
                $blocked[] = 'Return reply does not match the assessed stage and retrieved template.';
            }
            if (($returnReply['readiness'] ?? null) !== 'ready') {
                $blocked[] = 'Return investigation or reply preparation requires internal review; this is not a ready customer reply.';
            } elseif (array_diff($return['reply_requirements'], $returnReply['requirements_covered'] ?? []) !== []) {
                $blocked[] = 'Return reply does not cover the required stage content.';
            }
            if (($return['requires_internal_review'] ?? false) && ($returnReply['readiness'] ?? null) === 'ready') {
                $blocked[] = 'A specific exception after agreement or dispatch requires internal review; preserve the advanced stage.';
            }
            if ($return['stage'] === 'offer_options' && ($returnReply['readiness'] ?? null) === 'ready'
                && ! in_array($candidate['case_progress']['stage'], ['options_offered', 'awaiting_acceptance'], true)) {
                $blocked[] = 'Confirmed return conditions must advance to a proposal, not generic internal waiting.';
            }
        } elseif ($returnReply !== null) {
            $blocked[] = 'A return reply requires an applicable recorded assessment.';
        }
        if (($review['approved'] ?? false) !== true || ($review['problems'] ?? null) !== []
            || collect(self::CHECKS)->contains(fn ($key) => data_get($review, 'quality_checks.'.$key) !== true)
            || count($review['covered_request_ids'] ?? []) !== count($ids) || array_diff($ids, $review['covered_request_ids'] ?? []) !== []) {
            $blocked[] = 'Final independent review did not pass.';
        }
        if ($tools->policies === []) {
            $blocked[] = 'No applicable verified policy was read.';
        }
        foreach ($tools->policies as $policy) {
            if (array_intersect($policy['bound_intents'], ['*', ...$tools->plan['intents']]) === []) {
                $blocked[] = 'A retrieved policy is not applicable to the final request plan.';
            }
        }
        if ($tools->plan['unsupported_requests'] !== [] || in_array('unsupported', array_column($tools->plan['clauses'], 'intent'), true)) {
            $blocked[] = 'The Ticket includes a request requiring unsupported business handling.';
        }
        $orderIntents = ['production_order_status', 'logistics_tracking_delivery', 'production_cancellation', 'standard_size_change', 'refund_review', 'after_sales_complaint'];
        if (array_intersect($orderIntents, $tools->plan['intents']) !== []
            && (collect($tools->facts)->firstWhere('key', 'order.identity_match')['value'] ?? false) !== true) {
            $blocked[] = 'Current order identity and business facts are unverified.';
        }
        foreach ($emails as $email) {
            if (preg_match('/\[(?:CUSTOMER_NAME|ORDER_NUMBER|EMAIL|PHONE)\]|\{\{|\b(?:event|fact|document|policy):/i', $email)) {
                $blocked[] = 'Email contains unresolved placeholders or internal evidence references.';
            }
            // This workflow cannot verify a payment gateway execution. Conditional future terms are reviewed against history.
            if (preg_match('/\bwe(?: have|[’\']ve)\s+(?:refunded|(?:processed|issued)\s+[^.!?\n]{0,50}\brefund)|\brefund\s+(?:has been|was)\s+(?:issued|processed)/i', $email)) {
                $blocked[] = 'Refund execution requires independently verified payment evidence.';
            }
        }
        if ($report['status'] === 'blocked') {
            $blocked[] = 'The Agent reported an unresolved review failure.';
        }

        return ['reply_plan' => $reply, 'return_assessment' => $return, 'reply_readiness' => $returnReply['readiness'] ?? null,
            'draft_status' => $blocked === [] ? ($noReply ? 'no_reply' : 'draft') : ($noReply ? 'needs_review' : 'blocked'),
            'safety_flags' => array_values(array_unique($blocked)), 'review' => $review];
    }

    public function candidateHash(array $candidate): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode($canonical($candidate), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
    }
}
