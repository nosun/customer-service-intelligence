<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $this->store('qa_global_ask_dress_length', [
            'title' => '按裙长类型和标准尺码查询 Hollow-to-Hem',
            'knowledge_type' => 'fact',
            'applicability' => '适用于客户询问当前 dress 的肩到裙摆、hollow-to-hem 或标准成衣长度，并明确标准尺码。',
            'required_facts' => ['product.type', 'product.attributes.field_hemlinetrain', '客户询问的标准尺码', 'product.dress_length'],
            'policy_rules' => [
                '裙长数字必须由当前商品 field_hemlinetrain、客户询问尺码和已验证的 Hollow-to-Hem JSON 表共同查询，不能从标题或相似商品推断。',
                '回复同时提供厘米和英寸，并说明是约数。',
                '表格记录的是从颈窝到裙摆的 hollow-to-hem，不是 shoulder-to-hem；客户询问肩到裙摆时必须明确纠正测量起点，不能将两者当作相同尺寸。',
                'High-Low 款式只提供前侧 hollow-to-hem，不代表后侧裙摆长度。',
                '当前商品裙长类型、标准尺码或映射缺失时，不得猜测具体长度。',
                '产品问答已有商品上下文，回复不重复商品标题或 SN。',
            ],
            'decision_tree' => [
                ['condition' => '当前裙长类型和客户询问尺码均可在 JSON 表中匹配', 'outcome' => '返回对应 hollow-to-hem 厘米值及换算英寸值。'],
                ['condition' => '客户使用 shoulder-to-hem 描述', 'outcome' => '说明现有数据是 hollow-to-hem，并指出起点在颈窝而不是肩点。'],
                ['condition' => 'High-Low 款式', 'outcome' => '明确数值是前侧 hollow-to-hem。'],
                ['condition' => '裙长类型或尺码无法匹配', 'outcome' => '不提供猜测数值，标记商品长度事实缺失。'],
            ],
            'reply_strategy' => [
                ['stage' => '匹配事实', 'goal' => '按当前商品裙长类型和标准尺码定位唯一数值', 'content_requirements' => ['hemline', 'size', 'value_cm', 'value_in']],
                ['stage' => '澄清量法', 'goal' => '避免把 hollow-to-hem 误称为 shoulder-to-hem', 'content_requirements' => ['measurement_definition', 'not_equivalent_to']],
                ['stage' => '简洁回复', 'goal' => '直接给出长度并保留约数边界', 'content_requirements' => ['厘米', '英寸', '不重复商品标题或 SN']],
            ],
            'reply_templates' => [[
                'conditions' => ['当前商品裙长类型和标准尺码可匹配'],
                'body' => 'For standard US Size {{ size }}, the {{ hemline }} hollow-to-hem measurement is approximately {{ value_cm }} cm ({{ value_in }} in). This is measured from the hollow at the center base of the neck, not from the shoulder.',
            ]],
            'action_steps' => ['读取当前商品 field_hemlinetrain', '从问题提取标准尺码', '查询 dress-hollow-to-hem.json', '同时输出厘米和英寸并澄清测量起点'],
            'prohibited_claims' => ['不得把 hollow-to-hem 称为 shoulder-to-hem', '不得用 hollow-to-floor 替代 hollow-to-hem', '不得跨裙长类型或尺码复用数值', '不得把 High-Low 前长描述为后侧或完整裙长'],
            'escalation_rules' => ['商品没有明确裙长类型', '客户未提供标准尺码', 'JSON 表中不存在对应裙长或尺码'],
            'source_notes' => '业务提供 hellow to hem.csv，已规范为 resources/data/dress-hollow-to-hem.json；原始字段拼写 hellow 按业务含义规范为 hollow。',
        ], $now);

        $this->store('qa_global_ask_lining', [
            'title' => '商品是否带内衬的事实型回复',
            'knowledge_type' => 'fact',
            'applicability' => '适用于客户询问当前商品是否 fully lined 或带内衬。',
            'required_facts' => ['product.attributes.field_fullylined'],
            'policy_rules' => [
                '只使用当前商品 field_fullylined 事实回答，不从面料、颜色、图片或其他商品推断。',
                'field_fullylined=Yes 时可以直接说明 fully lined；No 时直接说明不是 fully lined。',
                '字段缺失或值不明确时不得猜测。',
                '产品问答已有商品上下文，回复不重复商品标题或 SN。',
            ],
            'decision_tree' => [
                ['condition' => 'field_fullylined=Yes', 'outcome' => '直接确认商品 fully lined。'],
                ['condition' => 'field_fullylined=No', 'outcome' => '直接说明商品不是 fully lined。'],
                ['condition' => '字段缺失或不明确', 'outcome' => '标记事实缺失，不生成猜测结论。'],
            ],
            'reply_strategy' => [['stage' => '直接回答', 'goal' => '使用当前商品 lining 事实给出简短结论', 'content_requirements' => ['field_fullylined']]],
            'reply_templates' => [
                ['conditions' => ['field_fullylined=Yes'], 'body' => 'Yes, this dress is fully lined.'],
                ['conditions' => ['field_fullylined=No'], 'body' => 'No, this dress is not fully lined.'],
            ],
            'action_steps' => ['读取当前商品 field_fullylined', '按 Yes/No 直接回答'],
            'prohibited_claims' => ['不得从面料或图片推断是否有内衬', '不得复用其他商品的 lining 结论'],
            'escalation_rules' => ['field_fullylined 缺失或值不明确'],
            'source_notes' => '当前商品目录 field_fullylined 是商品是否 fully lined 的唯一事实来源。',
        ], $now);
    }

    public function down(): void
    {
        DB::table('knowledge_articles')->whereIn('key', [
            'qa_global_ask_dress_length',
            'qa_global_ask_lining',
        ])->delete();
    }

    /** @param array<string, mixed> $content */
    private function store(string $key, array $content, mixed $now): void
    {
        DB::table('knowledge_articles')->updateOrInsert(
            ['key' => $key],
            [
                ...collect($content)->except(['required_facts', 'policy_rules', 'decision_tree', 'reply_strategy', 'reply_templates', 'action_steps', 'prohibited_claims', 'escalation_rules'])->all(),
                'status' => 'verified',
                'site_key' => null,
                'required_facts' => $this->json($content['required_facts']),
                'policy_rules' => $this->json($content['policy_rules']),
                'decision_tree' => $this->json($content['decision_tree']),
                'reply_strategy' => $this->json($content['reply_strategy']),
                'reply_templates' => $this->json($content['reply_templates']),
                'action_steps' => $this->json($content['action_steps']),
                'prohibited_claims' => $this->json($content['prohibited_claims']),
                'escalation_rules' => $this->json($content['escalation_rules']),
                'version' => 1,
                'reviewed_at' => $now,
                'automation_tier' => 'draft_low_risk',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
