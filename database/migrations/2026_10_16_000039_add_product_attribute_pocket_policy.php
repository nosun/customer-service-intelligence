<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $reference = DB::table('product_question_policy_candidates')->where('id', 17)->first();
        if ($reference === null) {
            return;
        }

        $now = now();
        $content = $this->content();
        $article = DB::table('knowledge_articles')->where('key', 'qa_global_ask_pockets')->first();
        $articleValues = $content;
        unset($articleValues['definition']);
        if ($article === null) {
            $articleId = DB::table('knowledge_articles')->insertGetId([
                'key' => 'qa_global_ask_pockets',
                'knowledge_type' => 'fact',
                'status' => 'verified',
                'site_key' => null,
                ...$articleValues,
                'source_notes' => '历史问答仅用于总结回复模式；口袋事实必须按当前 SN 从商品目录读取。证据包含有口袋与无口袋的不同商品，证明不能跨商品复用结论。',
                'version' => 1,
                'reviewed_by' => $reference->reviewed_by,
                'reviewed_at' => $now,
                'automation_tier' => 'draft_low_risk',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $articleId = $article->id;
            DB::table('knowledge_articles')->where('id', $articleId)->update([
                ...$articleValues,
                'status' => 'verified',
                'automation_tier' => 'draft_low_risk',
                'version' => ((int) $article->version) + 1,
                'updated_at' => $now,
            ]);
        }

        DB::table('product_question_policy_candidates')->updateOrInsert(
            [
                'product_question_policy_run_id' => $reference->product_question_policy_run_id,
                'scenario_key' => 'ask_pockets',
            ],
            [
                'commerce_site_id' => $reference->commerce_site_id,
                'scope' => 'global',
                'category' => 'product_details',
                ...$content,
                'evidence_question_ids' => $this->json([1414, 1935]),
                'conflicts' => '[]',
                'open_questions' => '[]',
                'confidence' => 0.98,
                'automation_tier' => 'draft_low_risk',
                'review_status' => 'confirmed',
                'review_notes' => '确认商品属性类问题使用历史经验总结回复结构，但属性值必须读取当前商品事实。',
                'knowledge_article_id' => $articleId,
                'reviewed_by' => $reference->reviewed_by,
                'reviewed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('product_question_strategy_evidence')->where('knowledge_article_id', $articleId)->delete();
        foreach ([1414, 1935] as $questionId) {
            if (! DB::table('product_questions')->where('id', $questionId)->exists()) {
                continue;
            }
            DB::table('product_question_strategy_evidence')->insert([
                'knowledge_article_id' => $articleId,
                'product_question_id' => $questionId,
                'product_question_analysis_id' => DB::table('product_question_analyses')
                    ->where('product_question_id', $questionId)
                    ->value('id'),
                'evidence_role' => 'historical_example',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Reviewed policy decisions are not reconstructed automatically.
    }

    /** @return array<string, mixed> */
    private function content(): array
    {
        return [
            'title' => '商品是否自带口袋的事实型回复',
            'definition' => '客户询问当前商品本身是否已经带有口袋；不包含要求新增或定制口袋。',
            'applicability' => '适用于 ask_pockets。要求增加口袋时切换 request_add_pockets，并独立核实工厂能力。',
            'required_facts' => $this->json(['product.sn', '当前商品 attributes 中明确的 pocket/pockets 字段，或其他已验证的当前商品口袋事实']),
            'policy_rules' => $this->json([
                '历史问答可以总结直接回答、引用商品事实和保持简洁的回复模式，但不能证明当前商品有无口袋。',
                '当前商品事实明确为有口袋时直接回答有；明确为无口袋时直接回答没有。',
                '当前商品目录未提供口袋字段时，不得把字段缺失解释为没有口袋，应标记事实缺失并等待核实。',
                '客户只问是否自带口袋时，不主动扩展为增加口袋、费用、退换货或客服引导。',
            ]),
            'decision_tree' => $this->json([
                ['condition' => '当前商品事实明确 pockets=true/Yes', 'outcome' => '直接确认该商品带口袋。'],
                ['condition' => '当前商品事实明确 pockets=false/No', 'outcome' => '直接说明该商品默认不带口袋。'],
                ['condition' => '当前商品事实没有口袋字段或相互矛盾', 'outcome' => '不得猜测，记录 product.pockets 缺失并进入商品资料核实。'],
                ['condition' => '客户要求增加口袋', 'outcome' => '切换 request_add_pockets，不能用 ask_pockets 策略承诺定制。'],
            ]),
            'reply_strategy' => $this->json([
                ['stage' => '识别问题', 'goal' => '区分自带口袋与增加口袋', 'content_requirements' => ['ask_pockets 或 request_add_pockets']],
                ['stage' => '读取事实', 'goal' => '只使用当前 SN 的商品事实', 'content_requirements' => ['product.sn', 'pocket/pockets 属性']],
                ['stage' => '直接回答', 'goal' => '给出简短明确结论', 'content_requirements' => ['不复述完整商品标题', '可保留 SN']],
            ]),
            'reply_templates' => $this->json([
                ['conditions' => ['当前商品明确带口袋'], 'body' => 'Yes, item {{ product.sn }} has pockets.'],
                ['conditions' => ['当前商品明确不带口袋'], 'body' => 'No, item {{ product.sn }} does not come with pockets.'],
            ]),
            'action_steps' => $this->json(['读取当前 SN 商品快照', '检查明确的 pocket/pockets 属性', '有明确事实才生成 yes/no 草稿']),
            'prohibited_claims' => $this->json(['不得把其他商品的历史回答套用到当前商品', '不得把未提供口袋字段解释为没有口袋', '不得在未进入 request_add_pockets 时承诺可以增加口袋']),
            'escalation_rules' => $this->json(['当前商品没有口袋字段', '商品属性与标题或图片信息矛盾', '客户进一步要求增加或修改口袋']),
        ];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
