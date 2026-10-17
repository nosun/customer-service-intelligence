<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rules = [
            '商品使用 US 标准尺码，不提供独立 Petite 尺码；不得把 Petite 当成额外可选尺码。',
            '生产按照当前商品 Size Chart 中 bust、waist、hips 和 hollow-to-floor 等测量值进行；客户应按实际测量值选择，而不是只按日常尺码或 Petite 标签选择。',
            '客户询问 Petite 且当前商品支持所述标准尺码时，说明该 US 标准尺码可选，但 Petite 比例应重点比较 hollow-to-floor 等测量值。',
            '标准比例不合适且当前商品支持 Custom Size 时，可以建议客户选择 Custom Size 并填写准确测量值。',
            'Custom Size 商品不接受退货或换货；只有在确实建议 Custom Size 时才简洁说明该限制。',
            '产品问答已有商品上下文，回复不重复商品标题或 SN。',
        ];
        $tree = [
            ['condition' => '客户询问 Petite 尺码，所述 US 标准尺码在当前商品 sizes 中', 'outcome' => '说明没有独立 Petite 尺码；该 US 标准尺码可选，但必须对照当前 Size Chart 的全部关键测量值。'],
            ['condition' => '客户的 Petite 比例与标准 hollow-to-floor 或其他关键测量不匹配，且商品支持 Custom Size', 'outcome' => '建议选择 Custom Size 填写准确测量值，并说明 Custom Size 不可退换。'],
            ['condition' => '所述标准尺码不在当前商品 sizes 中，或商品不支持 Custom Size', 'outcome' => '不得声称可选，按当前商品事实给出可用选项或进入人工核实。'],
        ];
        $strategy = [
            ['stage' => '说明尺码体系', 'goal' => '澄清使用 US 标准尺码且没有独立 Petite 尺码', 'content_requirements' => ['当前商品 sizes', 'US Size Chart']],
            ['stage' => '指导比较', 'goal' => '让客户用实际测量选择', 'content_requirements' => ['bust', 'waist', 'hips', 'hollow-to-floor']],
            ['stage' => '提供 Petite 方案', 'goal' => '标准比例不匹配时使用 Custom Size', 'content_requirements' => ['Custom Size 可用性', '准确测量值', '不可退换限制']],
        ];
        $templates = [[
            'conditions' => ['客户询问 Petite Size', '所述标准尺码可选', '商品支持 Custom Size'],
            'body' => 'This dress is available in standard US Size {{ requested_size }}, but we don’t offer a separate petite Size {{ requested_size }}. Our dresses are made according to the Size Chart, so please compare your bust, waist, hips, and hollow-to-floor measurements. For petite proportions, choose Custom Size and enter your exact measurements. Please note that custom-size dresses cannot be returned or exchanged.',
        ]];
        $prohibited = [
            '不得声称提供独立 Petite 尺码。',
            '不得仅凭客户日常尺码或 Petite 标签保证标准尺码合身。',
            '不得在当前商品不支持所述尺码或 Custom Size 时声称可以选择。',
            '不得遗漏 Custom Size 不可退换的限制。',
            '不得重复商品标题或 SN。',
        ];

        $this->appendPolicy('knowledge_articles', 'key', 'qa_global_ask_size_recommendation', $rules, $tree, $strategy, $templates, $prohibited);
        $this->appendPolicy('product_question_policy_candidates', 'scenario_key', 'ask_size_recommendation', $rules, $tree, $strategy, $templates, $prohibited);
        $this->appendPolicy('knowledge_articles', 'key', 'qa_global_request_custom_size', [
            'Custom Size 使用客户填写的准确测量值制作，并不提供独立 Petite 尺码。',
            'Custom Size 商品不接受退货或换货；在建议 Custom Size 时必须简洁说明该限制。',
        ], [], [], [], ['不得在建议 Custom Size 时遗漏不可退换限制。']);
        $this->appendPolicy('product_question_policy_candidates', 'scenario_key', 'request_custom_size', [
            'Custom Size 使用客户填写的准确测量值制作，并不提供独立 Petite 尺码。',
            'Custom Size 商品不接受退货或换货；在建议 Custom Size 时必须简洁说明该限制。',
        ], [], [], [], ['不得在建议 Custom Size 时遗漏不可退换限制。']);
    }

    public function down(): void
    {
        // Reviewed business decisions are not reconstructed automatically.
    }

    /** @param list<mixed> $rules @param list<mixed> $tree @param list<mixed> $strategy @param list<mixed> $templates @param list<mixed> $prohibited */
    private function appendPolicy(string $table, string $keyColumn, string $key, array $rules, array $tree, array $strategy, array $templates, array $prohibited): void
    {
        $record = DB::table($table)->where($keyColumn, $key)->first();
        if ($record === null) {
            return;
        }

        $updates = [];
        foreach (['policy_rules' => $rules, 'decision_tree' => $tree, 'reply_strategy' => $strategy, 'reply_templates' => $templates, 'prohibited_claims' => $prohibited] as $field => $items) {
            if ($items === []) {
                continue;
            }
            $current = json_decode((string) ($record->{$field} ?? '[]'), true);
            $updates[$field] = json_encode(array_values(array_merge(is_array($current) ? $current : [], $items)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $updates['updated_at'] = now();
        if ($table === 'knowledge_articles') {
            $updates['version'] = ((int) $record->version) + 1;
            $updates['source_notes'] = trim((string) $record->source_notes.' 业务确认：商品使用 US 标准尺码，不提供独立 Petite 尺码；Petite 比例可使用 Custom Size，且 Custom Size 不可退换。');
        } else {
            $updates['review_notes'] = trim((string) $record->review_notes.' 业务确认：增加 Petite 尺码选择、Custom Size 与不可退换边界。');
        }

        DB::table($table)->where('id', $record->id)->update($updates);
    }
};
