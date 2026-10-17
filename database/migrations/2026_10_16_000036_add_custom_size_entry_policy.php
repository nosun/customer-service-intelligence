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
        $article = DB::table('knowledge_articles')->where('key', 'qa_global_request_custom_size')->first();
        if ($article === null) {
            $articleId = DB::table('knowledge_articles')->insertGetId([
                'key' => 'qa_global_request_custom_size',
                'knowledge_type' => 'process',
                'status' => 'verified',
                'site_key' => null,
                ...$this->articleContent($content),
                'source_notes' => '业务负责人确认：dress 类型且 customizable=1 时，商品页尺码选择中提供 Custom Size，选择后填写测量值。',
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
                ...$this->articleContent($content),
                'status' => 'verified',
                'site_key' => null,
                'automation_tier' => 'draft_low_risk',
                'version' => ((int) $article->version) + 1,
                'updated_at' => $now,
            ]);
        }

        DB::table('product_question_policy_candidates')->updateOrInsert(
            [
                'product_question_policy_run_id' => $reference->product_question_policy_run_id,
                'scenario_key' => 'request_custom_size',
            ],
            [
                'commerce_site_id' => $reference->commerce_site_id,
                'scope' => 'global',
                'category' => 'website_usage',
                ...$content,
                'evidence_question_ids' => $this->json([887]),
                'conflicts' => '[]',
                'open_questions' => '[]',
                'confidence' => 0.98,
                'automation_tier' => 'draft_low_risk',
                'review_status' => 'confirmed',
                'review_notes' => '业务负责人确认 dress + customizable=1 的 Custom Size 页面操作规则；与尺码推荐策略分开。',
                'knowledge_article_id' => $articleId,
                'reviewed_by' => $reference->reviewed_by,
                'reviewed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        // Reviewed policy decisions are not reconstructed automatically.
    }

    /** @return array<string, mixed> */
    private function content(): array
    {
        return [
            'title' => 'Custom Size 测量值填写与尺码入口',
            'definition' => '客户询问如何在商品页选择 Custom Size、在哪里填写身体测量值，或为什么只看到测量说明但没有找到填写入口。',
            'applicability' => '适用于 type=dress 且 customizable=1 的商品。具体尺码推荐仍使用 ask_size_recommendation；完整网站下单步骤使用 ask_how_to_order。',
            'required_facts' => $this->json(['product.type', 'product.customizable', 'product.product_url', 'product.custom_size_entry_method']),
            'policy_rules' => $this->json([
                'dress 类型且 customizable=1 时，商品页尺码选择中提供 Custom Size 选项。',
                '选择 Custom Size 后，页面显示测量值填写字段；客户按页面字段填写并继续下单即可。',
                '客户只询问填写入口时直接给操作步骤，不要求先把测量值发到公开问答，也不默认转客服。',
                '只有页面实际报错、Custom Size 未出现或商品事实不符合适用条件时，才建议进一步人工处理。',
                '如果客户同时询问应选哪个尺码，组合 ask_size_recommendation；如果提到明确活动日期，组合 ask_delivery_time 并读取实时 ETA。',
            ]),
            'decision_tree' => $this->json([
                ['condition' => 'type=dress 且 customizable=1，客户询问在哪里填写测量值', 'outcome' => '引导在商品页 Size 处选择 Custom Size，然后填写出现的测量字段。'],
                ['condition' => '客户询问哪个尺码适合自己', 'outcome' => '组合 ask_size_recommendation，读取 Size Chart 并按测量值比较。'],
                ['condition' => 'Custom Size 没有出现或页面报错', 'outcome' => '先确认商品事实与页面状态，再进入人工处理。'],
            ]),
            'reply_strategy' => $this->json([
                ['stage' => '直接回答入口', 'goal' => '让客户立即找到填写位置', 'content_requirements' => ['商品页', 'Size', 'Custom Size']],
                ['stage' => '说明下一步', 'goal' => '让客户完成填写并继续下单', 'content_requirements' => ['填写页面显示的测量字段', '继续加入购物车']],
                ['stage' => '组合相关意图', 'goal' => '仅在客户确实提出时补充尺码推荐或交期', 'content_requirements' => ['Size Chart 或实时 ETA']],
            ]),
            'reply_templates' => $this->json([
                [
                    'conditions' => ['dress 类型且 customizable=1，客户询问如何填写测量值'],
                    'body' => 'On the product page, open the Size options and select Custom Size. The measurement fields will then appear for you to complete. Enter the requested measurements, choose the other product options, and continue to add the dress to your cart.',
                ],
            ]),
            'action_steps' => $this->json(['读取商品 type 与 customizable', '确认商品链接和 custom_size_entry_method', '直接给出 Size → Custom Size → 填写测量值的步骤', '按需组合尺码推荐和 ETA 策略']),
            'prohibited_claims' => $this->json(['不得把 request_custom_size 误判为必须先提供测量值的尺码推荐', '不得在客户未询问时扩展完整下单教程', '不得默认要求客户联系人工客服', '不得承诺未经实时 ETA 支持的到货日期']),
            'escalation_rules' => $this->json(['商品不是 dress 或 customizable 不为 1', 'Custom Size 选项在页面未出现', '页面报错或填写后无法提交', '客户要求页面未提供的复杂结构修改']),
        ];
    }

    /** @param array<string, mixed> $content */
    private function articleContent(array $content): array
    {
        unset($content['definition']);

        return $content;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
