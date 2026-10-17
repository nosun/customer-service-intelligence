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
        $article = DB::table('knowledge_articles')->where('key', 'qa_global_request_add_pockets')->first();
        $articleValues = $content;
        unset($articleValues['definition']);
        if ($article === null) {
            $articleId = DB::table('knowledge_articles')->insertGetId([
                'key' => 'qa_global_request_add_pockets',
                'knowledge_type' => 'policy',
                'status' => 'verified',
                'site_key' => null,
                ...$articleValues,
                'source_notes' => '工艺人员确认：除鱼尾包臀裙外，基本所有款式都可免费增加口袋；当前商品目录将该例外映射为 field_silhouette=Mermaid/Trumpet。历史 Dorris 问答用于总结自然的回复模式。',
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
                'source_notes' => '工艺人员确认：除鱼尾包臀裙外，基本所有款式都可免费增加口袋；当前商品目录将该例外映射为 field_silhouette=Mermaid/Trumpet。历史 Dorris 问答用于总结自然的回复模式。',
                'version' => ((int) $article->version) + 1,
                'updated_at' => $now,
            ]);
        }

        DB::table('product_question_policy_candidates')->updateOrInsert(
            [
                'product_question_policy_run_id' => $reference->product_question_policy_run_id,
                'scenario_key' => 'request_add_pockets',
            ],
            [
                'commerce_site_id' => $reference->commerce_site_id,
                'scope' => 'global',
                'category' => 'customization',
                ...$content,
                'evidence_question_ids' => $this->json([1414]),
                'conflicts' => '[]',
                'open_questions' => '[]',
                'confidence' => 0.98,
                'automation_tier' => 'draft_low_risk',
                'review_status' => 'confirmed',
                'review_notes' => '工艺规则已确认：Mermaid/Trumpet 不适合增加口袋；其他已知裙形可以免费增加，并在 Order Notes 备注。',
                'knowledge_article_id' => $articleId,
                'reviewed_by' => $reference->reviewed_by,
                'reviewed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('product_question_policy_candidates')->whereIn('id', [3, 8])->update([
            'review_status' => 'rejected',
            'review_notes' => '已由规范策略 request_add_pockets 替代；保留历史候选，不再用于回复。',
            'reviewed_by' => $reference->reviewed_by,
            'reviewed_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('product_question_strategy_evidence')->where('knowledge_article_id', $articleId)->delete();
        if (DB::table('product_questions')->where('id', 1414)->exists()) {
            DB::table('product_question_strategy_evidence')->insert([
                'knowledge_article_id' => $articleId,
                'product_question_id' => 1414,
                'product_question_analysis_id' => DB::table('product_question_analyses')
                    ->where('product_question_id', 1414)
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
            'title' => '按商品裙形判断是否可增加口袋',
            'definition' => '客户要求为当前商品增加口袋，或询问商品默认没有口袋时能否定制增加口袋。',
            'applicability' => '适用于 request_add_pockets。当前目录中“鱼尾包臀裙”按 product.attributes.field_silhouette=Mermaid/Trumpet 判断；不把 Sheath、Bodycon 或 Pencil 自动扩大为例外。',
            'required_facts' => $this->json(['product.sn', 'product.attributes.field_silhouette']),
            'policy_rules' => $this->json([
                '当前商品为 Mermaid/Trumpet 时，因臀部和大腿区域贴身，不适合增加口袋，不得作出可增加的承诺。',
                '当前商品裙形明确且不是 Mermaid/Trumpet 时，可以免费增加口袋，并引导客户在结账时于 Order Notes 写明要求。',
                '商品默认是否自带口袋与能否增加口袋是两个事实；默认状态未知时，可以只回答已经确认的定制能力，不得猜测默认状态。',
                '裙形字段缺失或冲突时不得默认允许，应进入商品事实核实和人工审核。',
            ]),
            'decision_tree' => $this->json([
                ['condition' => 'field_silhouette 包含 Mermaid/Trumpet', 'outcome' => '明确说明该贴身鱼尾轮廓不适合增加口袋，不作可增加承诺。'],
                ['condition' => 'field_silhouette 已知且不是 Mermaid/Trumpet', 'outcome' => '确认可以免费增加口袋；提示在结账时于 Order Notes 写明 “Please add pockets”。'],
                ['condition' => 'field_silhouette 缺失或冲突', 'outcome' => '不得猜测，记录 product.attributes.field_silhouette 缺失并进入人工核实。'],
            ]),
            'reply_strategy' => $this->json([
                ['stage' => '识别需求', 'goal' => '区分默认是否自带与客户要求增加', 'content_requirements' => ['request_add_pockets']],
                ['stage' => '读取商品事实', 'goal' => '按当前 SN 的裙形应用工艺例外', 'content_requirements' => ['product.sn', 'product.attributes.field_silhouette']],
                ['stage' => '直接回答', 'goal' => '给出明确结论和可执行下单指引', 'content_requirements' => ['免费或不适合', 'Order Notes 指引仅用于允许分支']],
            ]),
            'reply_templates' => $this->json([
                [
                    'conditions' => ['当前裙形已知且不是 Mermaid/Trumpet', '客户询问默认没有口袋时能否增加'],
                    'body' => 'This item does not come with pockets by default, but we would be happy to add them for you at no extra charge. Please write “Please add pockets” in the Order Notes section at checkout, and our tailors will add them for you.',
                ],
                [
                    'conditions' => ['当前裙形已知且不是 Mermaid/Trumpet', '默认口袋状态未知或客户只要求增加'],
                    'body' => 'Yes, pockets can be added to item {{ product.sn }} at no extra charge. Please write “Please add pockets” in the Order Notes section at checkout.',
                ],
                [
                    'conditions' => ['当前裙形为 Mermaid/Trumpet'],
                    'body' => 'Pockets are not recommended for item {{ product.sn }} because its fitted Mermaid/Trumpet silhouette is not suitable for adding them.',
                ],
            ]),
            'action_steps' => $this->json(['读取当前 SN 商品快照', '检查 attributes.field_silhouette', '按 Mermaid/Trumpet 例外选择回复分支', '保存草稿并等待人工确认']),
            'prohibited_claims' => $this->json([
                '不得仅凭其他商品的历史回复判断当前商品是否适合增加口袋',
                '不得对 Mermaid/Trumpet 款式承诺增加口袋',
                '不得在裙形缺失时默认允许增加口袋',
                '不得在默认口袋状态未知时声称商品默认没有口袋',
                '不得声称增加口袋会影响工期或退换货，除非另有已验证策略和事实',
            ]),
            'escalation_rules' => $this->json(['当前商品缺少 field_silhouette', '裙形字段相互冲突', '客户要求口袋以外的结构修改']),
        ];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
