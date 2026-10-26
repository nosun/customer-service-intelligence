<?php

namespace App\Services\TicketWork;

use Illuminate\Validation\ValidationException;

/** A communication recommendation, not an authoritative business state or scheduled task. */
class TicketResponseAssessment
{
    public const STAGES = ['undetermined', 'investigation', 'awaiting_customer', 'awaiting_internal', 'options_offered', 'awaiting_acceptance', 'awaiting_return', 'awaiting_document_review', 'refund_ready', 'refund_submitted', 'resolved'];

    public function validate(array $reply, array $runtime, array $context): array
    {
        // Existing v2 callers remain usable; silence always requires the explicit new contract.
        if (! isset($reply['response_decision'])) {
            $reply['response_decision'] = ['mode' => 'reply', 'reason' => 'A substantive reply is planned.', 'evidence_event_ids' => []];

            return $reply;
        }
        $decision = $reply['response_decision'];
        $progress = $reply['case_progress'] ?? [];
        if (! is_array($decision) || ! in_array($decision['mode'] ?? null, ['reply', 'acknowledge', 'no_reply'], true)
            || ! is_array($progress) || ! in_array($progress['stage'] ?? null, self::STAGES, true)
            || ! in_array($progress['waiting_for'] ?? null, ['none', 'customer', 'internal'], true)) {
            throw ValidationException::withMessages(['response_decision' => 'Invalid response mode, progress stage, or waiting party.']);
        }
        foreach ([$decision['reason'] ?? null, $progress['summary'] ?? null, $progress['next_action'] ?? null] as $text) {
            if (! is_string($text) || trim($text) === '' || mb_strlen($text) > 5000) {
                throw ValidationException::withMessages(['response_decision' => 'Reason, summary and next action must be nonempty text.']);
            }
        }
        $events = collect([...($context['messages'] ?? []), ...($context['related_messages'] ?? [])])->keyBy('event_id');
        foreach ([$decision, $progress] as $part) {
            $ids = $part['evidence_event_ids'] ?? null;
            if (! is_array($ids) || ! array_is_list($ids) || $ids === []
                || collect($ids)->contains(fn ($id) => ! is_int($id) || ! $events->has($id))) {
                throw ValidationException::withMessages(['response_decision' => 'Assessment must cite available message event IDs.']);
            }
        }
        if (! in_array($context['trigger_event_id'], $decision['evidence_event_ids'], true)) {
            throw ValidationException::withMessages(['response_decision' => 'The response decision must consider the latest message.']);
        }
        if ($decision['mode'] !== 'reply'
            && (array_diff($runtime['plan']['intents'] ?? [], ['conversation_update']) !== []
                || collect($reply['answer_units'] ?? [])->contains(fn ($u) => ($u['action_stage'] ?? '') !== 'information'))) {
            throw ValidationException::withMessages(['response_decision' => 'A substantive request or business decision cannot be silently reduced to an update.']);
        }
        if ($decision['mode'] === 'no_reply') {
            if (($context['history_incomplete'] ?? false) || ! collect($decision['evidence_event_ids'])->contains(fn ($id) => $events[$id]['role'] === 'agent')
                || ($progress['waiting_for'] === 'none' && $progress['stage'] !== 'resolved')) {
                throw ValidationException::withMessages(['response_decision' => 'No-reply requires complete history, prior agent context, and a waiting or resolved state.']);
            }
        }
        // A customer's promise date is text evidence; do not invent a timezone or an automation.
        $progress['follow_up_note'] = is_string($progress['follow_up_note'] ?? null) ? mb_substr($progress['follow_up_note'], 0, 1000) : null;
        $reply['response_decision'] = $decision;
        $reply['case_progress'] = $progress;
        $reply['history_incomplete'] = (bool) ($context['history_incomplete'] ?? false);

        return $reply;
    }
}
