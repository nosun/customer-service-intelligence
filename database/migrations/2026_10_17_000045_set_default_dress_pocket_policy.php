<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $ask = DB::table('knowledge_articles')->where('key', 'qa_global_ask_pockets')->first();
        if ($ask !== null) {
            DB::table('knowledge_articles')->where('id', $ask->id)->update([
                ...$this->askPocketPolicy(),
                'version' => ((int) $ask->version) + 1,
                'source_notes' => trim((string) $ask->source_notes.' 业务确认：所有 dress 默认不带口袋；回复无需重复当前页面已有的商品标题或 SN。'),
                'updated_at' => $now,
            ]);
        }

        $add = DB::table('knowledge_articles')->where('key', 'qa_global_request_add_pockets')->first();
        if ($add !== null) {
            DB::table('knowledge_articles')->where('id', $add->id)->update([
                ...$this->addPocketPolicy(),
                'version' => ((int) $add->version) + 1,
                'source_notes' => trim((string) $add->source_notes.' 业务确认：产品问答回复不重复商品标题或 SN。'),
                'updated_at' => $now,
            ]);
        }

        DB::table('product_question_policy_candidates')
            ->where('scenario_key', 'ask_pockets')
            ->where('review_status', 'confirmed')
            ->update([
                ...$this->askPocketPolicy(),
                'review_notes' => '业务确认：所有 dress 默认不带口袋；非 Mermaid/Trumpet 可免费增加并在 Order Notes 备注；回复不重复商品标题或 SN。',
                'updated_at' => $now,
            ]);
        DB::table('product_question_policy_candidates')
            ->where('scenario_key', 'request_add_pockets')
            ->where('review_status', 'confirmed')
            ->update([
                ...$this->addPocketPolicy(),
                'review_notes' => '业务确认：保留裙形例外和免费增加口袋规则；产品问答回复不重复商品标题或 SN。',
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        // Reviewed business decisions are not reconstructed automatically.
    }

    /** @return array<string, string> */
    private function askPocketPolicy(): array
    {
        return [
            'applicability' => '适用于客户询问 dress 是否默认带口袋，以及同时询问能否增加口袋的产品问答。',
            'required_facts' => $this->json(['product.type', '需要增加口袋时读取 product.attributes.field_silhouette']),
            'policy_rules' => $this->json([
                '所有 product.type=dress 的商品默认不带口袋，可以直接说明，不依赖商品快照中的 pocket 字段。',
                '产品标题和 SN 已由问答页面提供上下文，回复中不重复这些信息。',
                '客户同时关心增加口袋时，组合 verified request_add_pockets 策略，并按当前商品裙形判断。',
                '非 Mermaid/Trumpet 裙形可以免费增加口袋，并引导客户在结账时于 Order Notes 写明 “Please add pockets”。',
            ]),
            'decision_tree' => $this->json([
                ['condition' => 'product.type=dress 且客户询问默认是否有口袋', 'outcome' => '直接说明该裙子默认不带口袋，不重复标题或 SN。'],
                ['condition' => '同时询问能否增加且裙形不是 Mermaid/Trumpet', 'outcome' => '说明可免费增加，并提供 Order Notes 指引。'],
                ['condition' => '裙形为 Mermaid/Trumpet', 'outcome' => '说明默认不带口袋，且贴身鱼尾轮廓不适合增加。'],
                ['condition' => '不是 dress 或需要增加口袋但裙形缺失', 'outcome' => '不套用本默认规则或增加口袋承诺，进入事实核实。'],
            ]),
            'reply_strategy' => $this->json([
                ['stage' => '直接回答', 'goal' => '说明 dress 默认不带口袋', 'content_requirements' => ['不重复商品标题', '不重复 SN']],
                ['stage' => '提供定制选项', 'goal' => '只在裙形策略允许时给出免费增加口袋和 Order Notes 指引', 'content_requirements' => ['当前裙形', '费用', 'Order Notes']],
            ]),
            'reply_templates' => $this->json([
                [
                    'conditions' => ['dress 默认不带口袋', '当前裙形已知且不是 Mermaid/Trumpet'],
                    'body' => 'This dress does not come with pockets by default, but we’d be happy to add them at no extra charge. Simply write “Please add pockets” in the Order Notes section at checkout.',
                ],
                [
                    'conditions' => ['dress 默认不带口袋', '当前裙形为 Mermaid/Trumpet'],
                    'body' => 'This dress does not come with pockets by default. Its fitted Mermaid/Trumpet silhouette is not suitable for adding them.',
                ],
            ]),
            'action_steps' => $this->json(['确认当前商品 type=dress', '需要说明增加口袋时读取当前商品裙形', '应用 Mermaid/Trumpet 例外', '生成不含商品标题和 SN 的简洁回复']),
            'prohibited_claims' => $this->json([
                '不得把 dress 默认口袋状态描述为商品资料未确认。',
                '不得重复商品标题或 SN。',
                '不得对 Mermaid/Trumpet 款式承诺增加口袋。',
                '裙形缺失时不得承诺可以增加口袋。',
            ]),
            'escalation_rules' => $this->json(['商品类型不是 dress', '客户要求增加口袋但裙形字段缺失或冲突']),
        ];
    }

    /** @return array<string, string> */
    private function addPocketPolicy(): array
    {
        return [
            'applicability' => '适用于客户询问 dress 能否增加口袋。必须按当前裙形应用 Mermaid/Trumpet 例外。',
            'required_facts' => $this->json(['product.type', 'product.attributes.field_silhouette']),
            'policy_rules' => $this->json([
                '所有 product.type=dress 的商品默认不带口袋。',
                '当前裙形明确且不是 Mermaid/Trumpet 时，可以免费增加口袋，并引导客户在结账时于 Order Notes 写明要求。',
                '当前裙形为 Mermaid/Trumpet 时不适合增加口袋，不得作出可增加的承诺。',
                '回复不重复商品标题或 SN。',
            ]),
            'decision_tree' => $this->json([
                ['condition' => 'product.type=dress 且裙形已知并非 Mermaid/Trumpet', 'outcome' => '说明默认不带口袋，但可免费增加，并提供 Order Notes 指引。'],
                ['condition' => '裙形为 Mermaid/Trumpet', 'outcome' => '说明默认不带口袋，且贴身鱼尾轮廓不适合增加。'],
                ['condition' => '商品不是 dress 或裙形缺失、冲突', 'outcome' => '不承诺增加口袋，进入事实核实。'],
            ]),
            'reply_strategy' => $this->json([
                ['stage' => '读取商品事实', 'goal' => '按当前商品类型和裙形应用工艺规则', 'content_requirements' => ['product.type', 'field_silhouette']],
                ['stage' => '直接回答', 'goal' => '给出简短结论和必要的下单指引', 'content_requirements' => ['不重复商品标题', '不重复 SN', '允许分支说明免费和 Order Notes']],
            ]),
            'reply_templates' => $this->json([
                [
                    'conditions' => ['当前裙形已知且不是 Mermaid/Trumpet'],
                    'body' => 'This dress does not come with pockets by default, but we’d be happy to add them at no extra charge. Simply write “Please add pockets” in the Order Notes section at checkout.',
                ],
                [
                    'conditions' => ['当前裙形为 Mermaid/Trumpet'],
                    'body' => 'This dress does not come with pockets by default. Its fitted Mermaid/Trumpet silhouette is not suitable for adding them.',
                ],
            ]),
            'action_steps' => $this->json(['确认当前商品 type=dress', '读取当前商品裙形', '应用 Mermaid/Trumpet 例外', '允许时说明免费增加和 Order Notes 指引']),
            'prohibited_claims' => $this->json(['不得对 Mermaid/Trumpet 款式承诺增加口袋', '裙形缺失时不得默认允许', '不得重复商品标题或 SN', '不得声称增加口袋会影响工期或退换货，除非另有已验证策略和事实']),
            'escalation_rules' => $this->json(['商品类型不是 dress', '裙形字段缺失或冲突']),
        ];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
