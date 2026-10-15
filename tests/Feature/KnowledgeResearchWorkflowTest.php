<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\DecisionCase;
use App\Models\DecisionNode;
use App\Models\KnowledgeResearchBatch;
use App\Models\KnowledgeResearchBatchItem;
use App\Models\KnowledgeResearchQuestion;
use App\Services\KnowledgeResearch\KnowledgeResearchPrompt;
use App\Services\KnowledgeResearch\KnowledgeResearchSynthesizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeResearchWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_synthesis_prompt_does_not_repeat_resolved_or_existing_business_questions(): void
    {
        $prompt = app(KnowledgeResearchPrompt::class);

        $this->assertSame('knowledge-research-synthesis-v2', $prompt::VERSION);
        $this->assertStringContainsString(
            'Create a business question only for a business decision that remains unresolved after applying verified_knowledge.',
            $prompt->system(),
        );
        $this->assertStringContainsString(
            'Do not repeat a question already listed in existing_business_questions.',
            $prompt->system(),
        );
    }

    public function test_business_confirmation_can_publish_confirmed_knowledge_while_question_stays_open(): void
    {
        Storage::fake('local');
        $batch = KnowledgeResearchBatch::create([
            'batch_key' => 'partial-confirmation',
            'window_start' => now()->subDay(),
            'window_end' => now(),
            'sample_limit' => 0,
            'selection_seed' => 'partial-confirmation',
            'status' => 'completed',
            'selected_count' => 0,
            'completed_count' => 0,
            'failed_count' => 0,
            'node_count' => 0,
            'new_scenario_count' => 0,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        KnowledgeResearchQuestion::create([
            'knowledge_research_batch_id' => $batch->id,
            'question_key' => 'partially_confirmed_policy',
            'question' => '已经确认安全边界，但例外规则仍待确定吗？',
            'why_it_matters' => '避免把待定例外误写为正式政策。',
            'options' => [],
            'provisional_safe_handling' => '先按已确认边界执行。',
            'affected_scenario_keys' => [],
            'evidence_decision_node_ids' => [],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'knowledge-confirmation-');
        $this->assertNotFalse($path);
        file_put_contents($path, json_encode([
            'answers' => [[
                'question_key' => 'partially_confirmed_policy',
                'resolution' => '默认路径已经确认；例外仍待业务确认。',
                'keep_open' => true,
                'article' => [
                    'key' => 'partially_confirmed_policy',
                    'title' => '阶段性确认规则',
                    'knowledge_type' => 'policy',
                    'applicability' => '默认路径适用。',
                    'required_facts' => [],
                    'policy_rules' => ['默认路径已经确认'],
                    'decision_tree' => [],
                    'reply_strategy' => [],
                    'reply_templates' => [],
                    'action_steps' => [],
                    'prohibited_claims' => ['不得宣称例外已经确认'],
                    'escalation_rules' => [],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('helpdesk:research:confirm', [
            'file' => $path,
            '--reviewed-by' => 1,
        ])->assertSuccessful();

        $this->assertDatabaseHas('knowledge_research_questions', [
            'question_key' => 'partially_confirmed_policy',
            'status' => 'open',
            'resolution' => '默认路径已经确认；例外仍待业务确认。',
        ]);
        $this->assertDatabaseHas('knowledge_articles', [
            'key' => 'partially_confirmed_policy',
            'status' => 'verified',
        ]);
        $this->assertStringContainsString(
            '已确认部分：默认路径已经确认；例外仍待业务确认。',
            Storage::disk('local')->get('knowledge-research/knowledge-base.md'),
        );

        KnowledgeResearchQuestion::create([
            'knowledge_research_batch_id' => $batch->id,
            'question_key' => 'covered_by_existing_knowledge',
            'question' => '是否重复已有知识？',
            'why_it_matters' => '避免重复发布文章。',
            'options' => [],
            'provisional_safe_handling' => '引用已有知识。',
            'affected_scenario_keys' => [],
            'evidence_decision_node_ids' => [],
        ]);
        $reusePath = tempnam(sys_get_temp_dir(), 'knowledge-confirmation-reuse-');
        $this->assertNotFalse($reusePath);
        file_put_contents($reusePath, json_encode([
            'answers' => [[
                'question_key' => 'covered_by_existing_knowledge',
                'resolution' => '现有 verified 知识已经覆盖。',
                'existing_article_key' => 'partially_confirmed_policy',
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('helpdesk:research:confirm', [
            'file' => $reusePath,
            '--reviewed-by' => 1,
        ])->assertSuccessful();

        $this->assertDatabaseHas('knowledge_research_questions', [
            'question_key' => 'covered_by_existing_knowledge',
            'status' => 'resolved',
        ]);
        $this->assertDatabaseHas('knowledge_articles', [
            'key' => 'partially_confirmed_policy',
            'version' => 1,
        ]);
    }

    public function test_reviewed_batch_cannot_be_resynthesized_and_lose_business_confirmation(): void
    {
        config([
            'services.minimax.api_key' => 'secret',
            'services.minimax.base_url' => 'https://api.minimax.test/v1',
            'services.minimax.model' => 'MiniMax-M3',
        ]);
        Http::fake();
        $batch = KnowledgeResearchBatch::create([
            'batch_key' => 'reviewed-batch',
            'window_start' => now()->subDay(),
            'window_end' => now(),
            'sample_limit' => 0,
            'selection_seed' => 'reviewed-batch',
            'status' => 'completed',
            'selected_count' => 0,
            'completed_count' => 0,
            'failed_count' => 0,
            'node_count' => 0,
            'new_scenario_count' => 0,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        KnowledgeResearchQuestion::create([
            'knowledge_research_batch_id' => $batch->id,
            'question_key' => 'reviewed_question',
            'question' => '已经确认的问题会被覆盖吗？',
            'why_it_matters' => '业务确认不得丢失。',
            'options' => [],
            'provisional_safe_handling' => '保留确认。',
            'affected_scenario_keys' => [],
            'evidence_decision_node_ids' => [],
            'status' => 'resolved',
            'resolution' => '保留已确认结论。',
            'resolved_by' => 1,
            'resolved_at' => now(),
        ]);

        try {
            app(KnowledgeResearchSynthesizer::class)->synthesize($batch);
            $this->fail('Reviewed batch was unexpectedly re-synthesized.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Reviewed research results cannot be replaced by re-synthesis. Start a new batch instead.',
                $exception->getMessage(),
            );
        }

        Http::assertNothingSent();
        $this->assertDatabaseHas('knowledge_research_questions', [
            'question_key' => 'reviewed_question',
            'status' => 'resolved',
            'resolution' => '保留已确认结论。',
        ]);
    }

    public function test_it_researches_a_small_batch_and_persists_evidenced_knowledge_candidates(): void
    {
        Storage::fake('local');
        config([
            'services.minimax.api_key' => 'secret',
            'services.minimax.base_url' => 'https://api.minimax.test/v1',
            'services.minimax.model' => 'MiniMax-M3',
        ]);
        $account = ChannelAccount::create([
            'channel' => 'helpdesk',
            'external_account_id' => 'research-account',
        ]);
        $conversation = Conversation::create([
            'channel_account_id' => $account->id,
            'external_id' => 'research-ticket-1',
            'external_short_id' => 'R-1',
            'site_key' => 'dressafford.com',
            'silo' => 'tickets',
            'status' => 'solved',
            'subject' => 'Where is my order?',
            'created_at_external' => now()->subDays(5),
            'updated_at_external' => now()->subDays(4),
            'raw_payload' => [],
        ]);
        $request = $this->event($conversation, 'research-event-1', 'client', 'Where is my order?');
        $reply = $this->event($conversation, 'research-event-2', 'agent', 'We are checking the production status.');

        Http::fakeSequence()
            ->push($this->completion([
                'case_summary' => '客户询问订单进度。',
                'primary_category' => 'production',
                'primary_problem_type' => 'production_order_status',
                'case_risk' => 'normal',
                'nodes' => [[
                    'sequence' => 1,
                    'problem_type' => 'production_order_status',
                    'scenario_key' => 'production_status_inquiry',
                    'lifecycle_stage' => 'production',
                    'request_summary' => '客户询问订单制作进度。',
                    'intents' => ['查询订单制作进度'],
                    'observed_facts' => [],
                    'missing_facts' => ['实际制作状态'],
                    'required_facts' => ['订单制作状态', '预计完成时间'],
                    'required_actions' => ['查询订单和生产系统'],
                    'response_strategy' => '先核实状态，再回复已验证信息。',
                    'knowledge_gaps' => ['可承诺的预计完成时间口径'],
                    'prohibited_claims' => ['未查询前承诺完成日期'],
                    'observed_decision' => 'escalated',
                    'observed_action' => '客服表示将查询制作状态',
                    'stated_reason' => null,
                    'observed_outcome' => null,
                    'is_exception' => false,
                    'evidence' => [
                        ['event_id' => $request->id, 'role' => 'request'],
                        ['event_id' => $reply->id, 'role' => 'action'],
                    ],
                    'uncertainties' => ['最终状态未知'],
                    'extraction_confidence' => 0.9,
                ]],
            ], 'decision-request'))
            ->push($this->completion([
                'findings' => [[
                    'scenario_key' => 'production_status_inquiry',
                    'category' => 'production',
                    'problem_type' => 'production_order_status',
                    'title' => '制作进度查询',
                    'definition' => '客户询问已下单商品当前制作进度。',
                    'inclusion_rules' => ['已下单并询问制作状态'],
                    'exclusion_rules' => ['已经发货后的物流查询'],
                    'required_facts' => ['订单制作状态', '预计完成时间'],
                    'decision_tree' => [[
                        'condition' => '制作状态尚未核实',
                        'outcome' => '先查询生产系统，不承诺完成日期',
                        'evidence_decision_node_ids' => [1],
                    ]],
                    'reply_strategy' => [[
                        'stage' => 'received',
                        'goal' => '确认收到并说明将查询',
                        'content_requirements' => ['不虚构制作状态'],
                    ]],
                    'reply_templates' => [[
                        'stage' => 'received',
                        'conditions' => ['制作状态尚未核实'],
                        'subject' => 'Update on your order',
                        'body' => 'We are checking the current production status and will update you after it is verified.',
                        'evidence_decision_node_ids' => [1],
                    ]],
                    'required_actions' => ['查询生产系统'],
                    'prohibited_claims' => ['未核实前承诺完成日期'],
                    'uncertainties' => ['预计时间的批准口径未知'],
                    'evidence_decision_node_ids' => [1],
                ]],
                'business_questions' => [[
                    'question_key' => 'production_eta_commitment',
                    'question' => '客服可以按什么口径承诺制作完成时间？',
                    'why_it_matters' => '影响回复中的时间承诺。',
                    'options' => ['只转述系统日期', '增加人工缓冲期'],
                    'provisional_safe_handling' => '只说明正在查询，不承诺日期。',
                    'affected_scenario_keys' => ['production_status_inquiry'],
                    'evidence_decision_node_ids' => [1],
                ]],
            ], 'synthesis-request'));

        $this->artisan('helpdesk:research:run', [
            '--limit' => 1,
            '--since-days' => 180,
            '--batch' => 'pilot-001',
            '--seed' => 'pilot-seed',
        ])->expectsOutputToContain('批次 pilot-001：选中 1 张 Ticket')
            ->expectsOutputToContain('研究批次完成')
            ->assertSuccessful();

        $this->assertDatabaseHas('knowledge_research_batches', [
            'batch_key' => 'pilot-001',
            'status' => 'completed',
            'selected_count' => 1,
            'completed_count' => 1,
            'node_count' => 1,
            'new_scenario_count' => 1,
        ]);
        $this->assertDatabaseHas('knowledge_research_findings', [
            'scenario_key' => 'production_status_inquiry',
            'knowledge_status' => 'observed',
            'review_status' => 'candidate',
        ]);
        $this->assertDatabaseHas('knowledge_research_questions', [
            'question_key' => 'production_eta_commitment',
            'status' => 'open',
        ]);
        $this->artisan('helpdesk:research:publish', [
            '--scenario' => 'production_status_inquiry',
            '--status' => 'verified',
            '--reviewed-by' => 1,
        ])->expectsOutputToContain('已发布为 verified')
            ->assertSuccessful();
        $this->assertDatabaseHas('knowledge_articles', [
            'key' => 'production_status_inquiry',
            'status' => 'verified',
            'version' => 1,
            'reviewed_by' => 1,
        ]);
        Storage::disk('local')->assertExists('knowledge-research/pilot-001.md');
        Storage::disk('local')->assertExists('knowledge-research/knowledge-base.md');
        $batchReport = Storage::disk('local')->get('knowledge-research/pilot-001.md');
        $this->assertStringContainsString('**原始沟通上下文**', $batchReport);
        $this->assertStringContainsString('客户原文', $batchReport);
        $this->assertStringContainsString('Where is my order?', $batchReport);
        $this->assertStringContainsString('当时客服回复', $batchReport);
        $this->assertStringContainsString('We are checking the production status.', $batchReport);
        Http::assertSentCount(2);
    }

    public function test_it_synthesizes_large_batches_in_ticket_chunks_and_merges_results(): void
    {
        Storage::fake('local');
        config([
            'services.minimax.api_key' => 'secret',
            'services.minimax.base_url' => 'https://api.minimax.test/v1',
            'services.minimax.model' => 'MiniMax-M3',
            'services.minimax.research_synthesis_ticket_chunk_size' => 1,
        ]);
        $account = ChannelAccount::create([
            'channel' => 'helpdesk',
            'external_account_id' => 'chunked-research-account',
        ]);
        $batch = KnowledgeResearchBatch::create([
            'batch_key' => 'pilot-chunked',
            'window_start' => now()->subDays(180),
            'window_end' => now(),
            'sample_limit' => 2,
            'selection_seed' => 'chunked-seed',
            'status' => 'running',
            'selected_count' => 2,
            'completed_count' => 2,
            'failed_count' => 0,
            'node_count' => 2,
            'started_at' => now(),
        ]);
        $nodes = collect(['first', 'second'])->map(function (string $suffix) use ($account, $batch): DecisionNode {
            $conversation = Conversation::create([
                'channel_account_id' => $account->id,
                'external_id' => 'chunked-'.$suffix,
                'external_short_id' => 'C-'.$suffix,
                'site_key' => 'dressafford.com',
                'silo' => 'tickets',
                'status' => 'solved',
                'subject' => 'Chunked '.$suffix,
                'created_at_external' => now()->subDay(),
                'updated_at_external' => now(),
                'raw_payload' => [],
            ]);
            $case = DecisionCase::create([
                'conversation_id' => $conversation->id,
                'case_key' => 'chunked-'.$suffix,
                'primary_category' => 'production',
                'primary_problem_type' => 'production_status',
                'case_risk' => 'normal',
                'case_summary' => 'Chunked synthesis case.',
                'node_count' => 1,
                'extractor_version' => 'test',
                'input_hash' => hash('sha256', $suffix),
                'extracted_at' => now(),
            ]);
            KnowledgeResearchBatchItem::create([
                'knowledge_research_batch_id' => $batch->id,
                'conversation_id' => $conversation->id,
                'decision_case_id' => $case->id,
                'status' => 'completed',
            ]);

            return DecisionNode::create([
                'decision_case_id' => $case->id,
                'sequence' => 1,
                'problem_type' => 'production_status',
                'scenario_key' => 'chunked_'.$suffix,
                'lifecycle_stage' => 'production',
                'request_summary' => 'Chunked '.$suffix.' request.',
                'observed_decision' => 'answered',
                'extractor_version' => 'test',
            ]);
        });
        Http::fakeSequence()
            ->push($this->completion($this->synthesisOutput('chunked_first', $nodes[0]->id), 'chunk-first'))
            ->push($this->completion($this->synthesisOutput('chunked_second', $nodes[1]->id), 'chunk-second'));

        $this->artisan('helpdesk:research:synthesize', ['--batch' => $batch->batch_key])
            ->expectsOutputToContain('知识归纳完成')
            ->assertSuccessful();

        $this->assertDatabaseHas('knowledge_research_findings', [
            'knowledge_research_batch_id' => $batch->id,
            'scenario_key' => 'chunked_first',
        ]);
        $this->assertDatabaseHas('knowledge_research_findings', [
            'knowledge_research_batch_id' => $batch->id,
            'scenario_key' => 'chunked_second',
        ]);
        $this->assertCount(2, $batch->fresh()->synthesis_output['findings']);
        $this->assertSame(400, $batch->fresh()->usage['total_tokens']);
        Http::assertSentCount(2);
    }

    /** @param array<string, mixed> $output
     * @return array<string, mixed>
     */
    private function completion(array $output, string $id): array
    {
        return [
            'id' => $id,
            'choices' => [[
                'message' => ['content' => json_encode($output, JSON_UNESCAPED_UNICODE)],
            ]],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 100,
                'total_tokens' => 200,
            ],
        ];
    }

    private function synthesisOutput(string $scenarioKey, int $nodeId): array
    {
        return [
            'findings' => [[
                'scenario_key' => $scenarioKey,
                'category' => 'production',
                'problem_type' => 'production_status',
                'title' => 'Chunked finding',
                'definition' => 'A finding produced from one synthesis chunk.',
                'inclusion_rules' => ['Matching request'],
                'exclusion_rules' => [],
                'required_facts' => ['Order status'],
                'decision_tree' => [[
                    'condition' => 'Status is unknown',
                    'outcome' => 'Verify it before replying',
                    'evidence_decision_node_ids' => [$nodeId],
                ]],
                'reply_strategy' => [[
                    'stage' => 'verify',
                    'goal' => 'Use verified status',
                    'content_requirements' => ['Current order status'],
                ]],
                'reply_templates' => [],
                'required_actions' => ['Verify order status'],
                'prohibited_claims' => ['Do not guess'],
                'uncertainties' => [],
                'evidence_decision_node_ids' => [$nodeId],
            ]],
            'business_questions' => [],
        ];
    }

    private function event(Conversation $conversation, string $externalId, string $authorType, string $body): ConversationEvent
    {
        return ConversationEvent::create([
            'conversation_id' => $conversation->id,
            'external_id' => $externalId,
            'event_type' => 'message',
            'occurred_at' => now(),
            'author_type' => $authorType,
            'is_private' => false,
            'body_text' => $body,
            'content_hash' => hash('sha256', $body),
            'raw_payload' => [],
        ]);
    }
}
