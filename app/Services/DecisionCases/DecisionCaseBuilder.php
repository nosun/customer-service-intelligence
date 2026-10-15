<?php

namespace App\Services\DecisionCases;

use App\Models\Conversation;
use App\Models\DecisionCase;
use App\Models\DecisionCaseRun;
use App\Models\DecisionNode;
use App\Models\DecisionNodeEvidence;
use App\Services\AI\MiniMaxClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DecisionCaseBuilder
{
    public const VERSION = 'decision-case-extractor-v2';

    public function __construct(
        private readonly MiniMaxClient $client,
        private readonly DecisionCaseContextBuilder $contextBuilder,
        private readonly DecisionCasePrompt $prompt,
        private readonly DecisionCaseOutputValidator $validator,
    ) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function model(): string
    {
        return $this->client->model();
    }

    /** @return array{status: string, decision_case_id: ?int, run_id: ?int, nodes: int, error: ?string, api_called: bool, duration_ms: int, prompt_tokens: int, completion_tokens: int, total_tokens: int} */
    public function build(Conversation $conversation, bool $force = false): array
    {
        $prepared = $this->contextBuilder->build($conversation);
        $existing = DecisionCase::where('conversation_id', $conversation->id)->first();
        if ($existing !== null && $this->hasHumanReview($existing)) {
            return $this->result('skipped_reviewed', $existing->id, null, $existing->node_count);
        }
        if (! $force && $existing?->input_hash === $prepared['input_hash']) {
            return $this->result('skipped_unchanged', $existing->id, null, $existing->node_count);
        }

        $systemPrompt = $this->prompt->system();
        $userPrompt = $this->prompt->user($prepared['context']);
        $run = DecisionCaseRun::create([
            'conversation_id' => $conversation->id,
            'provider' => 'minimax',
            'model' => $this->client->model(),
            'prompt_version' => DecisionCasePrompt::VERSION,
            'extractor_version' => self::VERSION,
            'input_hash' => $prepared['input_hash'],
            'prompt_hash' => hash('sha256', $systemPrompt."\n".$userPrompt),
            'status' => 'running',
            'started_at' => now(),
        ]);
        $startedAt = hrtime(true);
        $completion = null;

        try {
            $completion = $this->client->structuredCompletion($systemPrompt, $userPrompt);
            try {
                $output = $this->validator->validate(
                    $completion['output'],
                    $prepared['event_ids'],
                    $prepared['event_authors'],
                );
            } catch (RuntimeException $validationError) {
                $retry = $this->client->structuredCompletion(
                    $systemPrompt,
                    $this->validationRetryPrompt($userPrompt, $completion['output'], $validationError),
                );
                $retry['usage'] = $this->combinedUsage($completion['usage'], $retry['usage']);
                $retry['usage']['validation_retries'] = 1;
                $completion = $retry;
                $output = $this->validator->validate(
                    $completion['output'],
                    $prepared['event_ids'],
                    $prepared['event_authors'],
                );
            }
            $output['case_risk'] = $this->higherRisk(
                $output['case_risk'],
                data_get($prepared, 'context.existing_rule_analysis.urgency'),
            );
            $case = DB::transaction(function () use ($conversation, $prepared, $output): DecisionCase {
                $case = DecisionCase::updateOrCreate(
                    ['conversation_id' => $conversation->id],
                    [
                        'case_key' => 'helpdesk:'.$conversation->external_id,
                        'primary_category' => $output['primary_category'],
                        'primary_problem_type' => $output['primary_problem_type'],
                        'case_risk' => $output['case_risk'],
                        'case_summary' => $output['case_summary'],
                        'node_count' => count($output['nodes']),
                        'review_status' => 'candidate',
                        'extractor_version' => self::VERSION,
                        'input_hash' => $prepared['input_hash'],
                        'extracted_at' => now(),
                    ],
                );
                $case->nodes()->delete();

                foreach ($output['nodes'] as $nodeOutput) {
                    $node = DecisionNode::create([
                        'decision_case_id' => $case->id,
                        'sequence' => $nodeOutput['sequence'],
                        'problem_type' => $this->nullableString($nodeOutput['problem_type'] ?? null),
                        'scenario_key' => $this->nullableString($nodeOutput['scenario_key'] ?? null),
                        'lifecycle_stage' => $nodeOutput['lifecycle_stage'],
                        'request_summary' => trim($nodeOutput['request_summary']),
                        'intents' => $nodeOutput['intents'],
                        'observed_facts' => $nodeOutput['observed_facts'],
                        'missing_facts' => $nodeOutput['missing_facts'],
                        'required_facts' => $nodeOutput['required_facts'],
                        'required_actions' => $nodeOutput['required_actions'],
                        'response_strategy' => $nodeOutput['response_strategy'],
                        'knowledge_gaps' => $nodeOutput['knowledge_gaps'],
                        'prohibited_claims' => $nodeOutput['prohibited_claims'],
                        'observed_decision' => $nodeOutput['observed_decision'],
                        'observed_action' => $this->nullableString($nodeOutput['observed_action'] ?? null),
                        'stated_reason' => $this->nullableString($nodeOutput['stated_reason'] ?? null),
                        'observed_outcome' => $this->nullableString($nodeOutput['observed_outcome'] ?? null),
                        'is_exception' => $nodeOutput['is_exception'],
                        'uncertainties' => $nodeOutput['uncertainties'],
                        'extraction_confidence' => $nodeOutput['extraction_confidence'],
                        'review_status' => 'candidate',
                        'extractor_version' => self::VERSION,
                    ]);

                    foreach ($this->uniqueEvidence($nodeOutput['evidence']) as $evidence) {
                        DecisionNodeEvidence::create([
                            'decision_node_id' => $node->id,
                            'conversation_event_id' => $evidence['event_id'],
                            'evidence_role' => $evidence['role'],
                            'source' => 'ai',
                            'extractor_version' => self::VERSION,
                        ]);
                    }
                }

                return $case;
            });
            $durationMs = $this->durationMs($startedAt);
            $run->update([
                'status' => 'completed',
                'structured_output' => $output,
                'usage' => $completion['usage'],
                'provider_request_id' => $completion['request_id'],
                'duration_ms' => $durationMs,
                'finished_at' => now(),
            ]);

            return $this->result(
                'completed',
                $case->id,
                $run->id,
                count($output['nodes']),
                apiCalled: true,
                durationMs: $durationMs,
                usage: $completion['usage'],
            );
        } catch (Throwable $exception) {
            $durationMs = $this->durationMs($startedAt);
            $run->update([
                'status' => 'failed',
                'structured_output' => $completion['output'] ?? null,
                'usage' => $completion['usage'] ?? null,
                'provider_request_id' => $completion['request_id'] ?? null,
                'duration_ms' => $durationMs,
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                'finished_at' => now(),
            ]);

            return $this->result(
                'failed',
                $existing?->id,
                $run->id,
                0,
                $exception->getMessage(),
                true,
                $durationMs,
                $completion['usage'] ?? [],
            );
        }
    }

    private function hasHumanReview(DecisionCase $case): bool
    {
        return $case->review_status !== 'candidate'
            || $case->nodes()->whereIn('review_status', ['confirmed', 'corrected'])->exists();
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param  list<array{event_id: int, role: string}>  $evidence
     * @return list<array{event_id: int, role: string}>
     */
    private function uniqueEvidence(array $evidence): array
    {
        $unique = [];
        foreach ($evidence as $item) {
            $unique[$item['event_id'].':'.$item['role']] = $item;
        }

        return array_values($unique);
    }

    private function durationMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function higherRisk(string $aiRisk, mixed $ruleRisk): string
    {
        $rank = ['normal' => 0, 'high' => 1, 'critical' => 2];
        if (! is_string($ruleRisk) || ! isset($rank[$ruleRisk])) {
            return $aiRisk;
        }

        return $rank[$ruleRisk] > $rank[$aiRisk] ? $ruleRisk : $aiRisk;
    }

    /** @param array<string, mixed> $output */
    private function validationRetryPrompt(string $userPrompt, array $output, RuntimeException $error): string
    {
        return $userPrompt."\n\nYour previous JSON was rejected by deterministic validation: "
            .$error->getMessage()
            ."\nReturn a corrected complete JSON object. Do not add a node without client request evidence. "
            .'Use only allowed event IDs and evidence roles. Previous JSON: '
            .json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $first
     * @param  array<string, mixed>  $second
     * @return array<string, mixed>
     */
    private function combinedUsage(array $first, array $second): array
    {
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $field) {
            $second[$field] = (int) ($first[$field] ?? 0) + (int) ($second[$field] ?? 0);
        }

        return $second;
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array{status: string, decision_case_id: ?int, run_id: ?int, nodes: int, error: ?string, api_called: bool, duration_ms: int, prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    private function result(
        string $status,
        ?int $decisionCaseId,
        ?int $runId,
        int $nodes,
        ?string $error = null,
        bool $apiCalled = false,
        int $durationMs = 0,
        array $usage = [],
    ): array {
        return [
            'status' => $status,
            'decision_case_id' => $decisionCaseId,
            'run_id' => $runId,
            'nodes' => $nodes,
            'error' => $error,
            'api_called' => $apiCalled,
            'duration_ms' => $durationMs,
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
        ];
    }
}
