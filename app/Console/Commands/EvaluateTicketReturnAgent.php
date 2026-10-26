<?php

namespace App\Console\Commands;

use App\Services\Agents\TicketAgentOutput;
use App\Services\Agents\TicketAgentWorker;
use App\Services\Agents\TicketReturnAssessment;
use App\Services\TicketWork\TicketDraftPlanGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Real SDK/model evaluation on synthetic conversations; no database access or external business tools. */
class EvaluateTicketReturnAgent extends Command
{
    protected $signature = 'tickets:agent-evaluate-returns {--case=* : Synthetic case keys; defaults to all} {--provider=omniroute}';

    protected $description = 'Evaluate return-stage understanding using synthetic conversations and the real Agents SDK';

    public function handle(TicketAgentWorker $worker, TicketReturnAssessment $returns): int
    {
        $suite = json_decode(file_get_contents(resource_path('data/ticket-return-stage-eval-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $selected = $this->option('case');
        $cases = collect($suite['cases'])->when($selected !== [], fn ($c) => $c->whereIn('key', $selected));
        if ($suite['synthetic'] !== true || $cases->isEmpty() || array_diff($selected, $cases->pluck('key')->all()) !== []) {
            $this->error('Only known synthetic cases may be evaluated.');

            return self::FAILURE;
        }
        $failed = false;
        foreach ($cases as $case) {
            $ledger = [];
            $assessment = null;
            $checkpoints = [];
            $context = ['messages' => array_map(fn ($message, $index) => [...$message, 'event_id' => $index + 1], $case['messages'], array_keys($case['messages'])),
                'related_messages' => [], 'history_incomplete' => false, 'trigger_event_id' => count($case['messages']), 'documents' => [], 'operator_guidance' => null];
            $context['evidence_refs'] = array_map(fn ($m) => 'event:'.$m['event_id'], $context['messages']);
            $initial = ['case_key' => 'synthetic:'.$case['key'], 'input_hash' => hash('sha256', json_encode($case)),
                'site_key' => 'example.test', 'subject' => 'Synthetic standard-size dress return',
                'policy_catalog' => [['key' => 'fixture_return', 'title' => 'Synthetic ordinary return policy', 'intents' => ['*'], 'version' => 1]]];
            try {
                $report = $worker->run($initial, $this->option('provider'), function ($name, $args) use (&$ledger, &$assessment, $context, $suite, $returns) {
                    $refs = collect($ledger)->flatMap(fn ($call) => $call['result']['evidence_refs'] ?? [])->unique()->all();
                    $result = match ($name) {
                        'read_conversation' => $context,
                        'record_plan' => ['plan' => app(TicketDraftPlanGuard::class)->validate($args, true), 'evidence_refs' => []],
                        'read_return_playbook' => ['playbook' => $returns->agentPlaybook(), 'evidence_refs' => ['strategy:return@'.$returns->playbook()['version']]],
                        'read_policy' => $args['key'] === 'fixture_return' ? ['policy' => ['key' => 'fixture_return', 'policy_rules' => [$suite['policy']]], 'evidence_refs' => ['policy:fixture_return@1']]
                            : ['status' => 'unavailable', 'evidence_refs' => []],
                        'read_site_return_policy' => ['status' => 'read', 'content' => $suite['policy'], 'evidence_refs' => ['site_policy:return@fixture']],
                        'read_order' => ['facts' => array_map(fn ($key, $value) => ['key' => $key, 'value' => $value],
                            ['order.identity_match', 'order.return_items', 'order.return_amounts', 'order.currency', 'order.shipping_status'],
                            [true, [['type' => 'dress', 'size' => '6', 'quantity' => 1, 'total_amount' => 100]], ['goods_amount' => 100, 'shipping_amount' => 20, 'pay_amount' => 120], 'USD', 'Delivered']),
                            'evidence_refs' => ['fact:order.identity_match', 'fact:order.return_items', 'fact:order.return_amounts', 'fact:order.currency', 'fact:order.shipping_status']],
                        'list_documents' => ['documents' => [], 'evidence_refs' => []],
                        'read_return_status' => ['carrier_status' => 'unavailable', 'warehouse_status' => 'unavailable', 'limitations' => ['Outbound delivery is not return receipt.'], 'evidence_refs' => ['capability:return_status']],
                        'record_return_assessment' => $returns->recordForTool($args, $refs),
                        'read_return_template' => $assessment === null ? throw new RuntimeException('Assessment required.')
                            : [...$returns->agentPlaybook()['stages'][$assessment['stage']], 'stage' => $assessment['stage'], 'evidence_refs' => ['template:'.$assessment['template_id'].'@'.$returns->playbook()['version']]],
                        default => throw new RuntimeException('Tool unavailable in this synthetic evaluation.'),
                    };
                    if ($name === 'record_return_assessment') {
                        $assessment = $result['assessment'] ?? null;
                    }
                    $ledger[] = ['name' => $name, 'arguments' => $args, 'result' => $result];

                    return $result;
                }, function (array $checkpoint) use (&$checkpoints): void {
                    $checkpoints[] = $checkpoint;
                });
                $review = $report['reviews'][array_key_last($report['reviews'])];
                $passed = in_array($assessment['stage'] ?? null, $case['allowed_stages'] ?? [$case['expected_stage']], true) && $review['approved'] === true
                    && ($assessment['customer_position'] ?? null) !== ($case['forbidden_position'] ?? 'forbidden')
                    && $report['status'] === 'needs_review'
                    && collect(TicketAgentOutput::CHECKS)->every(fn ($check) => ($review['quality_checks'][$check] ?? false) === true)
                    && hash_equals(app(TicketAgentOutput::class)->candidateHash($report['candidate']), $review['candidate_hash']);
                $result = ['case' => $case['key'], 'passed' => $passed, 'expected_stage' => $case['expected_stage'],
                    'allowed_stages' => $case['allowed_stages'] ?? [$case['expected_stage']],
                    'actual_stage' => $assessment['stage'] ?? null, 'assessment' => $assessment, 'report' => $report, 'evidence' => $ledger, 'checkpoints' => $checkpoints];
            } catch (Throwable $e) {
                $passed = false;
                $result = ['case' => $case['key'], 'passed' => false, 'error' => class_basename($e),
                    'error_detail' => $e->getMessage(), 'assessment' => $assessment, 'evidence' => $ledger, 'checkpoints' => $checkpoints];
            }
            $directory = storage_path('app/private/ticket-agent-evaluations');
            File::ensureDirectoryExists($directory, 0700);
            $path = $directory.'/'.$case['key'].'-'.Str::uuid().'.json';
            file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            chmod($path, 0600);
            $this->line($case['key'].' '.($passed ? 'PASS' : 'FAIL').' stage='.($assessment['stage'] ?? 'none').' report='.$path);
            $failed = $failed || ! $passed;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
