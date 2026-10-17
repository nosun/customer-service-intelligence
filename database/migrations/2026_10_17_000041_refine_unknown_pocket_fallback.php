<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $article = DB::table('knowledge_articles')->where('key', 'qa_global_ask_pockets')->first();
        if ($article === null) {
            return;
        }

        $rules = $this->decode($article->policy_rules);
        $rules[] = '默认口袋字段缺失、但当前商品裙形明确且 request_add_pockets 已验证时，组合两条策略：透明说明默认配置未确认，再提供按裙形判断的增加口袋方案；不得把字段缺失解释为默认没有口袋。';
        $tree = $this->decode($article->decision_tree);
        $tree[] = [
            'condition' => '当前商品没有明确口袋字段，但裙形明确且存在 verified request_add_pockets 策略',
            'outcome' => '说明商品资料未确认默认是否带口袋；随后按裙形说明是否可增加，不再只回复“需要核实”。',
        ];
        $templates = $this->decode($article->reply_templates);
        $templates[] = [
            'conditions' => ['默认口袋状态未知', '当前裙形已知且不是 Mermaid/Trumpet'],
            'body' => 'The current product details do not confirm whether item {{ product.sn }} includes pockets by default. However, pockets can be added at no extra charge. Please write “Please add pockets” in the Order Notes section at checkout.',
        ];

        DB::table('knowledge_articles')->where('id', $article->id)->update([
            'policy_rules' => $this->json(array_values(array_unique($rules, SORT_REGULAR))),
            'decision_tree' => $this->json($tree),
            'reply_templates' => $this->json($templates),
            'version' => ((int) $article->version) + 1,
            'updated_at' => now(),
        ]);

        DB::table('product_question_policy_candidates')
            ->where('scenario_key', 'ask_pockets')
            ->where('review_status', 'confirmed')
            ->update([
                'policy_rules' => $this->json(array_values(array_unique($rules, SORT_REGULAR))),
                'decision_tree' => $this->json($tree),
                'reply_templates' => $this->json($templates),
                'review_notes' => '已确认：默认口袋事实缺失但裙形明确时，组合 request_add_pockets 给出透明且可执行的回复，不虚构默认配置。',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Reviewed policy decisions are not reconstructed automatically.
    }

    /** @return list<mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
