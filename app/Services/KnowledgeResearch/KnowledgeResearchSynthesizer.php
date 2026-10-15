<?php

namespace App\Services\KnowledgeResearch;

use App\Models\DecisionNode;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeResearchBatch;
use App\Models\KnowledgeResearchFinding;
use App\Models\KnowledgeResearchQuestion;
use App\Services\AI\MiniMaxClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KnowledgeResearchSynthesizer
{
    public function __construct(
        private readonly MiniMaxClient $client,
        private readonly KnowledgeResearchPrompt $prompt,
        private readonly KnowledgeResearchOutputValidator $validator,
    ) {}

    /** @return array{findings: int, questions: int, new_scenarios: int, usage: array<string, mixed>, output: array<string, mixed>} */
    public function synthesize(KnowledgeResearchBatch $batch): array
    {
        if ($batch->findings()->where('review_status', '!=', 'candidate')->exists()
            || $batch->questions()->where(function ($questions): void {
                $questions
                    ->whereNotNull('resolution')
                    ->orWhereNotNull('resolved_at');
            })->exists()) {
            throw new RuntimeException('Reviewed research results cannot be replaced by re-synthesis. Start a new batch instead.');
        }

        $nodes = DecisionNode::query()
            ->whereHas('decisionCase', fn ($cases) => $cases->whereHas('researchBatchItems', fn ($items) => $items
                ->where('knowledge_research_batch_id', $batch->id)
                ->where('status', 'completed')))
            ->with(['decisionCase:id,conversation_id,primary_category,primary_problem_type,case_risk', 'evidence.event:id,author_type,body_text'])
            ->orderBy('id')
            ->get();

        if ($nodes->isEmpty()) {
            return ['findings' => 0, 'questions' => 0, 'new_scenarios' => 0, 'usage' => [], 'output' => [
                'findings' => [],
                'business_questions' => [],
            ]];
        }

        $existing = KnowledgeResearchFinding::query()
            ->where('knowledge_research_batch_id', '!=', $batch->id)
            ->latest('id')
            ->limit(200)
            ->get(['scenario_key', 'category', 'problem_type', 'title', 'definition'])
            ->unique('scenario_key')
            ->values();
        $baseContext = [
            'verified_knowledge' => KnowledgeArticle::query()
                ->where('status', 'verified')
                ->get([
                    'key', 'title', 'knowledge_type', 'site_key', 'applicability', 'required_facts',
                    'policy_rules', 'decision_tree', 'reply_strategy', 'action_steps',
                    'prohibited_claims', 'escalation_rules', 'version',
                ])
                ->toArray(),
            'existing_scenarios' => $existing->toArray(),
            'existing_business_questions' => KnowledgeResearchQuestion::query()
                ->where('knowledge_research_batch_id', '!=', $batch->id)
                ->latest('id')
                ->limit(200)
                ->get(['question_key', 'question', 'status', 'resolution'])
                ->unique('question_key')
                ->values()
                ->toArray(),
        ];

        $systemPrompt = $this->prompt->system();
        $output = ['findings' => [], 'business_questions' => []];
        $usage = [];
        foreach ($this->nodeChunks($nodes) as $chunk) {
            $context = [
                ...$baseContext,
                'decision_nodes' => $this->serializeNodes($chunk),
            ];
            $result = $this->completeAndValidate(
                $systemPrompt,
                $this->prompt->user($context),
                $chunk->pluck('id')->all(),
                $existing->pluck('scenario_key')->all(),
            );
            $output = $this->mergeOutputs($output, $result['output']);
            $usage = $this->combinedUsage($usage, $result['usage']);
        }
        $output = $this->validator->validate(
            $output,
            $nodes->pluck('id')->all(),
            $existing->pluck('scenario_key')->all(),
        );
        $existingKeys = KnowledgeResearchFinding::query()
            ->where('knowledge_research_batch_id', '!=', $batch->id)
            ->pluck('scenario_key')
            ->flip();
        $newScenarioCount = collect($output['findings'])
            ->reject(fn (array $finding): bool => $existingKeys->has($finding['scenario_key']))
            ->count();

        DB::transaction(function () use ($batch, $output): void {
            $batch->findings()->delete();
            $batch->questions()->delete();
            foreach ($output['findings'] as $finding) {
                KnowledgeResearchFinding::create([
                    'knowledge_research_batch_id' => $batch->id,
                    ...$finding,
                    'knowledge_status' => 'observed',
                    'review_status' => 'candidate',
                    'source_hash' => hash('sha256', json_encode($finding, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                ]);
            }
            foreach ($output['business_questions'] as $question) {
                KnowledgeResearchQuestion::create([
                    'knowledge_research_batch_id' => $batch->id,
                    ...$question,
                    'status' => 'open',
                ]);
            }
        });

        return [
            'findings' => count($output['findings']),
            'questions' => count($output['business_questions']),
            'new_scenarios' => $newScenarioCount,
            'usage' => $usage,
            'output' => $output,
        ];
    }

    private function serializeNodes($nodes): array
    {
        return $nodes->map(fn (DecisionNode $node): array => [
            'decision_node_id' => $node->id,
            'category' => $node->decisionCase->primary_category,
            'problem_type' => $node->problem_type,
            'scenario_key' => $node->scenario_key,
            'lifecycle_stage' => $node->lifecycle_stage,
            'request_summary' => $node->request_summary,
            'intents' => $node->intents,
            'observed_facts' => $node->observed_facts,
            'missing_facts' => $node->missing_facts,
            'required_facts' => $node->required_facts,
            'observed_decision' => $node->observed_decision,
            'observed_action' => $node->observed_action,
            'stated_reason' => $node->stated_reason,
            'observed_outcome' => $node->observed_outcome,
            'required_actions' => $node->required_actions,
            'response_strategy' => $node->response_strategy,
            'knowledge_gaps' => $node->knowledge_gaps,
            'prohibited_claims' => $node->prohibited_claims,
            'is_exception' => $node->is_exception,
            'uncertainties' => $node->uncertainties,
            'evidence' => $node->evidence->map(fn ($evidence): array => [
                'event_id' => $evidence->conversation_event_id,
                'role' => $evidence->evidence_role,
                'author_type' => $evidence->event?->author_type,
                'text' => mb_substr(trim((string) $evidence->event?->body_text), 0, 3000),
            ])->values()->all(),
        ])->all();
    }

    private function nodeChunks($nodes): array
    {
        $chunkSize = max(1, (int) config('services.minimax.research_synthesis_ticket_chunk_size', 5));

        return $nodes
            ->groupBy(fn (DecisionNode $node): int => (int) $node->decision_case_id)
            ->values()
            ->chunk($chunkSize)
            ->map(fn ($groups) => $groups->flatMap(fn ($group) => $group)->values())
            ->all();
    }

    /** @return array{output: array<string, mixed>, usage: array<string, mixed>} */
    private function completeAndValidate(
        string $systemPrompt,
        string $userPrompt,
        array $allowedNodeIds,
        array $existingScenarioKeys,
    ): array {
        $usage = [];
        $retryPrompt = $userPrompt;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $completion = null;
            try {
                $completion = $this->client->structuredCompletion($systemPrompt, $retryPrompt);
                $usage = $this->combinedUsage($usage, $completion['usage']);
                $output = $this->validator->validate(
                    $completion['output'],
                    $allowedNodeIds,
                    $existingScenarioKeys,
                );
                $usage['validation_retries'] = $attempt;

                return ['output' => $output, 'usage' => $usage];
            } catch (RuntimeException $error) {
                if ($attempt === 2) {
                    throw $error;
                }
                $retryPrompt = $userPrompt."\n\nThe previous response failed deterministic parsing or validation: "
                    .$error->getMessage()
                    ."\nReturn a corrected complete JSON object using only these decision node IDs: "
                    .json_encode($allowedNodeIds, JSON_THROW_ON_ERROR)
                    .'. Use only scenario keys from this chunk or these existing scenario keys: '
                    .json_encode($existingScenarioKeys, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                    .'. All list fields must be JSON arrays and all required strings must be non-empty.';
                if ($completion !== null) {
                    $retryPrompt .= ' Previous JSON: '.json_encode(
                        $completion['output'],
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                    );
                }
            }
        }

        throw new RuntimeException('Knowledge research synthesis failed after retries.');
    }

    private function mergeOutputs(array $first, array $second): array
    {
        $findings = collect([...$first['findings'], ...$second['findings']])
            ->groupBy('scenario_key')
            ->map(function ($items): array {
                $finding = $items->first();
                foreach ($items->skip(1) as $item) {
                    foreach (['inclusion_rules', 'exclusion_rules', 'required_facts', 'required_actions', 'prohibited_claims', 'uncertainties', 'evidence_decision_node_ids'] as $field) {
                        $finding[$field] = array_values(array_unique([...$finding[$field], ...$item[$field]], SORT_REGULAR));
                    }
                    foreach (['decision_tree', 'reply_strategy', 'reply_templates'] as $field) {
                        $finding[$field] = array_values(array_unique([...$finding[$field], ...$item[$field]], SORT_REGULAR));
                    }
                }

                return $finding;
            })
            ->values()
            ->all();
        $questions = collect([...$first['business_questions'], ...$second['business_questions']])
            ->groupBy('question_key')
            ->map(function ($items): array {
                $question = $items->first();
                foreach ($items->skip(1) as $item) {
                    foreach (['options', 'affected_scenario_keys', 'evidence_decision_node_ids'] as $field) {
                        $question[$field] = array_values(array_unique([...$question[$field], ...$item[$field]], SORT_REGULAR));
                    }
                }

                return $question;
            })
            ->values()
            ->all();

        return ['findings' => $findings, 'business_questions' => $questions];
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
        $second['validation_retries'] = (int) ($first['validation_retries'] ?? 0)
            + (int) ($second['validation_retries'] ?? 0);

        return $second;
    }
}
