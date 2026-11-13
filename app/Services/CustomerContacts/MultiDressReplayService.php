<?php

namespace App\Services\CustomerContacts;

use App\Models\AiWorkflow;
use App\Models\CustomerContactEffect;
use App\Models\CustomerContactRound;
use App\Models\FollowUp;
use Carbon\CarbonImmutable;
use RuntimeException;

class MultiDressReplayService
{
    public function run(array $fixture): array
    {
        $policy = app(MultiDressPolicy::class);
        $template = app(MultiDressTemplate::class);
        $manager = app(MultiDressRoundManager::class);
        $snapshot = $this->snapshot($fixture['historical'], $fixture['identity']);
        $current = $this->snapshot($fixture['current'], $fixture['identity']);
        $current['contacts'][0]['released'] = true;
        $c = $this->communication();
        $checks = [];
        $check = function (string $case, mixed $actual, mixed $expected) use (&$checks): void {
            if ($actual !== $expected) {
                throw new RuntimeException('Replay assertion failed: '.$case);
            }
            $checks[] = ['case' => $case, 'outcome' => $actual, 'passed' => true];
        };
        $check('current_already_handled', $policy->assess($current, $c)['decision'], 'skip');
        $check('historical_incomplete_communication', $policy->assess($snapshot, [...$c, 'complete' => false])['decision'], 'manual');
        $check('historical_clean_baseline_is_synthetic', $policy->assess($snapshot, $c)['decision'], 'prepare_first_email');
        $draft = $template->render($snapshot, ['question_module' => 'purpose_and_keep_plan']);
        $check('hold_notice', str_contains($draft['body'], 'on hold'), true);
        $check('48h_notice', str_contains($draft['body'], 'within 48 hours'), true);
        $check('no_internal_timeout_disclosure', str_contains($draft['body'], 'automatically'), false);
        $round = $manager->ingest($snapshot, $c);
        $same = $manager->ingest($snapshot, $c);
        $check('stable_first_round', $same->id, $round->id);
        $origin = now()->toImmutable();
        $input = new class($snapshot, $c) extends MultiDressInput
        {
            public array $snapshot;

            public array $communication;

            public function __construct(array $s, array $c)
            {
                $this->snapshot = $s;
                $this->communication = $c;
            }

            public function capture(CustomerContactRound $round): array
            {
                $c = $this->communication;
                $c['checkpoint']['captured_at'] = now()->toIso8601String();

                return ['snapshot' => $this->snapshot, 'communication' => $c,
                    'hash' => MultiDressInput::hash(['snapshot' => $this->snapshot, 'communication' => $c])];
            }
        };
        $transport = new class extends GmailFirstRoundTransport
        {
            public int $calls = 0;

            public bool $unknown = false;

            public function verifyAccount(string $siteKey): array
            {
                return ['emailAddress' => config("gmail.accounts.$siteKey.email")];
            }

            public function send(array $frozenMail): array
            {
                $this->calls++;

                return $this->unknown ? ['state' => 'unknown'] : ['state' => 'matched', 'gmail_id' => 'REPLAY-GMAIL', 'thread_id' => 'REPLAY-THREAD',
                    'rfc822_id' => '<replay@example.invalid>', 'sent_at' => now()->toIso8601String()];
            }

            public function reconcile(CustomerContactEffect $effect): array
            {
                return ['state' => 'unknown'];
            }
        };
        app()->instance(MultiDressInput::class, $input);
        app()->instance(GmailFirstRoundTransport::class, $transport);
        // The runner owns an isolated in-memory database and fake transports; no real live adapter can be resolved.
        config(['customer_contacts.mode' => 'live', 'customer_contacts.send_enabled' => true,
            'customer_contacts.sites.ellenbridals.send_enabled' => true]);
        AiWorkflow::create(['workflow_key' => 'work/multi-dress-first-round-v1', 'workflow_version' => '1.0.0',
            'status' => 'active', 'center' => 'work', 'domain' => 'customer_contacts',
            'definition_checksum' => hash_file('sha256', base_path('n8n/workflows/work__multi-dress-first-round-v1.json'))]);
        $work = $manager->requestWork($round->refresh());
        $draftService = app(MultiDressDraftService::class);
        $draftService->start($work, 'REPLAY-execution');
        $analysis = ['decision' => 'prepare_first_email', 'question_module' => 'purpose_and_keep_plan', 'evidence_refs' => ['fixture:items'], 'blocking_reasons' => []];
        $rendered = $draftService->render($work->refresh(), 'REPLAY-execution', $analysis);
        $draftService->complete($work->refresh(), 'REPLAY-execution', $analysis, ['approved' => true,
            'reviewed_input_hash' => $work->input_hash, 'reviewed_content_hash' => $rendered['mail']['content_hash'], 'violations' => []]);
        $mailService = app(MultiDressMailExecutor::class);
        $check('mock_send', $mailService->send($round->id)['decision'], 'sent');
        $check('repeat_send', $mailService->send($round->id)['decision'], 'already_attempted');
        $check('send_call_count', $transport->calls, 1);
        $check('deadline_48h', $round->fresh()->sent_at->addHours(48)->equalTo($round->fresh()->wait_until), true);
        $round->update(['note_state' => 'failed']);
        $wait = app(MultiDressWaitService::class);
        $at = $round->fresh()->wait_until;
        CarbonImmutable::setTestNow($at);
        $check('note_failure_blocks_timeout', $wait->inspect($round->id, $at)['reason'], 'console_note_not_recorded');
        $round->update(['note_state' => 'recorded', 'waiting_state' => 'waiting']);
        $round->effects()->where('effect_kind', 'sent_note')->update(['state' => 'succeeded',
            'payload' => ['contact_id' => $snapshot['contacts'][0]['id']],
            'receipt' => ['contact_baseline' => MultiDressConsoleExecutor::contactBaseline($snapshot['contacts'][0])]]);
        $check('before_deadline', $wait->inspect($round->id, $at->subSecond())['decision'], 'waiting');
        $check('two_dress_due', $wait->inspect($round->id, $at)['decision'], 'timeout_preview_ready');
        $check('source_execution_missing', app(MultiDressConsoleExecutor::class)->previewTimeout($round->id)['reason'], 'source_timeout_contract_missing');
        foreach ([['complete', false, 'communication_incomplete'], ['fresh', false, 'communication_stale'],
            ['identity_unique', false, 'identity_ambiguous'], ['human_takeover', true, 'human_takeover'], ['delivery_failure', true, 'delivery_failure']] as [$key, $value, $expected]) {
            $input->communication = [...$c, $key => $value];
            $round->update(['waiting_state' => 'waiting']);
            $check($key, $wait->inspect($round->id, $at)['reason'], $expected);
        }
        $input->communication = $c;
        $input->snapshot['items'][0]['size'] = '4';
        $check('item_changed', $wait->inspect($round->id, $at)['reason'], 'item_scope_changed');
        $input->snapshot = $snapshot;
        $input->snapshot['other_blockers'] = [['source' => 'eta']];
        $check('other_blocker', $wait->inspect($round->id, $at)['reason'], 'other_blockers');
        $input->snapshot = $snapshot;
        $trigger = $round->trigger_snapshot;
        $round->update(['trigger_snapshot' => [...$trigger, 'original_count' => 3]]);
        $check('three_reduced_to_two', $wait->inspect($round->id, $at)['reason'], 'original_three_or_more');
        $round->update(['trigger_snapshot' => [...$trigger, 'verified' => false]]);
        $check('unproven_original_trigger', $wait->inspect($round->id, $at)['reason'], 'original_trigger_unproven');
        $round->update(['trigger_snapshot' => $trigger]);
        $input->communication['reply_seen'] = true;
        $check('partial_reply', $wait->inspect($round->id, $at)['decision'], 'human_handoff');
        $check('repeat_reply', $wait->inspect($round->id, $at)['decision'], 'human_handoff');
        $check('one_handoff', FollowUp::count(), 1);
        $check('no_timeout_effect', CustomerContactEffect::where('effect_kind', 'two_dress_timeout')->count(), 0);
        $check('one_first_email_effect', CustomerContactEffect::where('effect_kind', 'first_email')->count(), 1);
        $check('one_note_effect', CustomerContactEffect::where('effect_kind', 'sent_note')->count(), 1);

        // Independent second synthetic order exercises unknown send and reconciliation in the real executor.
        $unknownSnapshot = $snapshot;
        $unknownSnapshot['identity']['oid']++;
        $unknownSnapshot['identity']['order_number'] = 'ebd20990101-2';
        $input->snapshot = $unknownSnapshot;
        $input->communication = $this->communication();
        $unknownRound = $manager->ingest($unknownSnapshot, $input->communication);
        $unknownWork = $manager->requestWork($unknownRound);
        $draftService->start($unknownWork, 'REPLAY-unknown-execution');
        $unknownRendered = $draftService->render($unknownWork->refresh(), 'REPLAY-unknown-execution', $analysis);
        $draftService->complete($unknownWork->refresh(), 'REPLAY-unknown-execution', $analysis, ['approved' => true,
            'reviewed_input_hash' => $unknownWork->input_hash, 'reviewed_content_hash' => $unknownRendered['mail']['content_hash'], 'violations' => []]);
        $transport->unknown = true;
        $check('unknown_send', $mailService->send($unknownRound->id)['decision'], 'send_unknown');
        $check('unknown_timer_not_started', $unknownRound->fresh()->wait_until, null);
        $check('unknown_not_resent', $mailService->send($unknownRound->id)['decision'], 'already_attempted');
        $unknownEffect = CustomerContactEffect::where('round_id', $unknownRound->id)->where('effect_kind', 'first_email')->firstOrFail();
        $check('unknown_reconcile_no_match', $mailService->reconcile($unknownEffect->id)['decision'], 'send_unknown');
        $check('unknown_wait_path_blocked', $wait->inspect($unknownRound->id, now()->toImmutable())['reason'], 'send_not_verified');
        $check('total_mock_send_calls', $transport->calls, 2);
        config(['customer_contacts.mode' => 'shadow']);
        $check('shadow_sender_disabled', $mailService->send($unknownRound->id)['decision'], 'disabled');
        $check('no_console_effect_executed', CustomerContactEffect::where('effect_kind', 'sent_note')->whereNotNull('attempt_started_at')->count(), 0);

        return ['mode' => 'isolated_php_replay', 'current' => 'already_handled', 'assertions_passed' => count($checks),
            'real_emails_sent' => 0, 'real_console_writes' => 0, 'real_n8n_executions' => 0,
            'review' => 'fixed_simulated_review_not_independent_AI', 'checks' => $checks, 'mail_preview' => $draft,
            'synthetic_conditions' => ['complete communication history', 'verified original two-dress trigger', 'policy approval', 'send receipt and reply events']];
    }

    private function snapshot(array $order, array $identity): array
    {
        return ['schema_version' => 'multi_dress.first_round.input.v1', 'identity' => array_diff_key($identity, ['contact_id' => true]),
            'customer' => ['email' => 'customer@example.invalid'],
            'order' => ['paid' => $order['status']['payment_status'] === 1, 'cancelled' => $order['status']['order_status'] === -1,
                'shipped' => $order['status']['shipping_status'] !== 0, 'production_started' => false, 'on_hold' => $order['status']['order_status'] === 0],
            'items' => array_map(fn ($i) => ['id' => $i['id'], 'type' => $i['type'], 'active' => $i['status'] === 1,
                'item_code' => $i['item_code'], 'color' => $i['color'], 'size' => $i['size'], 'quantity' => $i['quantity']], $order['items']),
            'contacts' => [['id' => $identity['contact_id'], 'source' => 'multi_dress', 'version' => 1, 'released' => false, 'deleted' => false]],
            'trigger' => ['verified' => true, 'original_count' => 2, 'item_ids' => array_column($order['items'], 'id'), 'rule_version' => 'SYNTHETIC'],
            'human_hold' => false, 'other_blockers' => [], 'intent_complete' => false,
            'policy' => ['verified' => true, 'version' => '2026-11-11', 'url' => config('customer_contacts.sites.ellenbridals.policy_url')]];
    }

    private function communication(): array
    {
        return ['complete' => true, 'fresh' => true, 'identity_unique' => true, 'prior_contact' => false, 'reply_seen' => false,
            'delivery_failure' => false, 'human_takeover' => false, 'evidence_refs' => [], 'checkpoint' => ['captured_at' => now()->toIso8601String()]];
    }
}
