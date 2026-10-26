<?php

namespace App\Console\Commands;

use App\Services\Agents\TicketReturnAssessment;
use App\Services\Agents\TicketReturnFlowDocumentation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/** Offline definition, coverage and documentation checks; no model, Ticket or business API calls. */
class CheckTicketReturnFlow extends Command
{
    protected $signature = 'tickets:return-flow-check {--write-doc : Regenerate the fixed workflow documentation file} {--json : Emit a machine-readable check result}';

    protected $description = 'Validate the return workflow, replay deterministic routing cases and check generated documentation';

    public function handle(TicketReturnAssessment $returns, TicketReturnFlowDocumentation $documentation): int
    {
        try {
            $definition = $returns->playbook();
            $suite = json_decode(File::get(resource_path('data/ticket-return-routing-cases-v1.json')), true, flags: JSON_THROW_ON_ERROR);
            $semantic = json_decode(File::get(resource_path('data/ticket-return-stage-eval-v1.json')), true, flags: JSON_THROW_ON_ERROR);
            if ($suite['synthetic'] !== true || $semantic['synthetic'] !== true || $suite['cases'] === [] || $semantic['cases'] === []) {
                throw new RuntimeException('Return workflow requires nonempty synthetic regression datasets.');
            }
            $rules = [];
            $keys = [];
            foreach ($suite['cases'] as $case) {
                if (in_array($case['key'], $keys, true) || array_diff(array_keys($case['condition_statuses']), TicketReturnAssessment::CONDITIONS) !== []) {
                    throw new RuntimeException('Duplicate case or unknown condition in return routing tests.');
                }
                $keys[] = $case['key'];
                $input = array_replace($suite['base_assessment'], $case['changes']);
                foreach ($input['conditions'] as &$condition) {
                    $condition['status'] = $case['condition_statuses'][$condition['key']] ?? $condition['status'];
                }
                unset($condition);
                $result = $returns->record($input, $suite['evidence_refs']);
                if ($result['stage'] !== $case['expected_stage'] || $result['routing_rule'] !== $case['expected_rule']) {
                    throw new RuntimeException('Return routing regression failed: '.$case['key']);
                }
                $rules[] = $result['routing_rule'];
            }
            if (array_diff(array_column($definition['routing'], 'id'), $rules) !== []) {
                throw new RuntimeException('Some return routing rules have no passing regression case.');
            }
            $semanticKeys = array_column($semantic['cases'], 'key');
            $referenced = [];
            foreach ($definition['stages'] as $stage) {
                if (array_diff($stage['evaluation_cases'], $semanticKeys) !== []) {
                    throw new RuntimeException('Return node references an unknown semantic evaluation case.');
                }
                $referenced = [...$referenced, ...$stage['evaluation_cases']];
            }
            if (count(array_unique($semanticKeys)) !== count($semanticKeys) || array_diff($semanticKeys, $referenced) !== []) {
                throw new RuntimeException('Duplicate or unassigned return semantic evaluation cases.');
            }
            $path = base_path(TicketReturnFlowDocumentation::PATH);
            $rendered = $documentation->render($definition);
            if ($this->option('write-doc')) {
                File::ensureDirectoryExists(dirname($path));
                File::put($path, $rendered);
            }
            if (! File::exists($path) || File::get($path) !== $rendered) {
                throw new RuntimeException('Return documentation is missing or stale. Run tickets:return-flow-check --write-doc.');
            }
            $report = ['passed' => true, 'workflow_key' => $definition['workflow_key'], 'version' => $definition['version'],
                'definition_hash' => hash_file('sha256', resource_path('data/ticket-return-playbook-v1.json')),
                'nodes' => count($definition['stages']), 'routes' => count($definition['routing']), 'routing_cases_passed' => count($keys),
                'semantic_cases_registered' => count($semanticKeys), 'semantic_model_replay_performed' => false,
                'documentation' => $path, 'capability_gaps' => array_filter($definition['capabilities'], fn ($c) => $c['status'] !== 'available')];
            $this->line($this->option('json') ? json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : 'PASS return@'.$report['version'].' nodes='.$report['nodes'].' routes='.$report['routes'].' routing_cases='.$report['routing_cases_passed'].' semantic_cases_registered='.$report['semantic_cases_registered']."\nDocumentation: ".$path."\nNo model replay or business API calls performed. Known capability gaps: ".implode(', ', array_keys($report['capability_gaps'])));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->line($this->option('json') ? json_encode(['passed' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR) : 'FAIL '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
