<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_question_policy_candidates')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('product_question_analyses')
                ->whereIn('intent_key', ['ask_shipping_country', 'ask_shipping_cost'])
                ->update(['intent_key' => 'ask_shipping_options', 'updated_at' => now()]);

            $countryPolicy = DB::table('product_question_policy_candidates')
                ->where('scenario_key', 'ask_shipping_country')
                ->where('scope', 'global')
                ->orderByDesc('id')
                ->first();
            $costPolicy = DB::table('product_question_policy_candidates')
                ->where('scenario_key', 'ask_shipping_cost')
                ->where('scope', 'global')
                ->orderByDesc('id')
                ->first();

            if ($costPolicy === null) {
                return;
            }

            $countryEvidence = $countryPolicy === null ? [] : $this->jsonList($countryPolicy->evidence_question_ids);
            $evidence = array_values(array_unique([
                ...$countryEvidence,
                ...$this->jsonList($costPolicy->evidence_question_ids),
            ]));
            $now = now();
            $article = DB::table('knowledge_articles')->where('id', $costPolicy->knowledge_article_id)->first();

            $content = $this->content();
            DB::table('product_question_policy_candidates')->where('id', $costPolicy->id)->update([
                ...$content,
                'scenario_key' => 'ask_shipping_options',
                'evidence_question_ids' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'conflicts' => '[]',
                'open_questions' => '[]',
                'confidence' => 0.9500,
                'review_notes' => '已将原国家配送策略与运费/关税策略合并。所有站点统一按当前站点 shipping/options 接口查询国家支持、方式、发货后运输时效和预估费用；商家承担进口关税，但不扩张至 VAT、销售税或地方税。',
                'updated_at' => $now,
            ]);

            if ($article !== null) {
                $articleContent = $content;
                unset($articleContent['definition']);
                DB::table('knowledge_articles')->where('id', $article->id)->update([
                    ...$articleContent,
                    'key' => 'qa_global_ask_shipping_options',
                    'source_notes' => '由 qa_global_ask_shipping_country 与 qa_global_ask_shipping_cost 合并；证据 '.count($evidence).' 条；人工确认的跨站点统一配送与进口关税策略。',
                    'version' => ((int) $article->version) + 1,
                    'updated_at' => $now,
                ]);

                $this->mergeEvidence((int) $article->id, $evidence, $now);
            }

            if ($countryPolicy !== null) {
                DB::table('product_question_policy_candidates')->where('id', $countryPolicy->id)->update([
                    'review_status' => 'rejected',
                    'review_notes' => '已合并至 ask_shipping_options（策略 #'.$costPolicy->id.'），保留本记录仅用于历史审计。',
                    'knowledge_article_id' => null,
                    'updated_at' => $now,
                ]);

                if ($countryPolicy->knowledge_article_id !== null) {
                    DB::table('product_question_strategy_evidence')
                        ->where('knowledge_article_id', $countryPolicy->knowledge_article_id)
                        ->delete();
                    DB::table('knowledge_articles')->where('id', $countryPolicy->knowledge_article_id)->update([
                        'status' => 'deprecated',
                        'source_notes' => '已合并并由 qa_global_ask_shipping_options 替代；原证据已迁移至统一策略。',
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // The original split cannot be reconstructed after new analyses use the unified intent.
    }

    /** @return list<mixed> */
    private function jsonList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @param list<int> $questionIds */
    private function mergeEvidence(int $articleId, array $questionIds, mixed $now): void
    {
        DB::table('product_question_strategy_evidence')->where('knowledge_article_id', $articleId)->delete();

        foreach ($questionIds as $questionId) {
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

    /** @return array<string, string> */
    private function content(): array
    {
        $json = fn (array $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'title' => '配送国家、方式、运输时效、费用与进口关税',
            'definition' => '客户在购买前询问目的地是否支持配送、可用配送方式、发货后的运输时间、预估运费或进口关税。',
            'applicability' => '适用于所有站点的售前配送问题；不适用于商品制作周期、包含制作周期的总到货 ETA、已下单订单追踪或特殊税费判断。',
            'required_facts' => $json([
                '客户询问的国家或地区及其两位 country_code',
                'GET /shipping/options?country={country_code} 返回的 supported、country_name、currency、methods[].name、methods[].transit_days 与 methods[].estimated_cost',
                '当前问答所属站点的 site.public_base_url',
                '已确认的业务规则：商家承担所有进口关税',
            ]),
            'policy_rules' => $json([
                '以当前问答所属站点 GET /shipping/options?country={country_code} 的结果作为可达国家、配送方式、运输时间与预估运费的权威事实；请求中不传 site 参数。',
                '不得使用历史问答中的固定运费、国家支持情况或运输时效作为当前事实。',
                'supported=true 且 methods 非空时，按客户所问内容说明支持状态、配送方式、运输时间或预估运费；supported=false 时明确说明当前不支持配送。',
                'estimated_cost 是国家级预估费用，最终运费以结账页按实际订单计算的金额为准。',
                'transit_days 仅表示发货后的运输时间，不包含商品制作或备货周期。',
                '所有进口关税由商家承担，客户收货时无需额外支付进口关税。',
                '进口关税规则不得扩张为 VAT、销售税、消费税、地方税、支付手续费或其他非进口关税费用的承诺。',
                '向客户提供 {{ site.public_base_url }}/shipping-info.html，以便查询更完整的配送信息。',
            ]),
            'decision_tree' => $json([
                ['condition' => '无法从问题中识别国家或地区，且问题需要目的地数据', 'outcome' => '先追问客户需要配送到哪个国家或地区。'],
                ['condition' => '查询成功且 supported=true，并返回一个或多个配送方式', 'outcome' => '按客户实际询问的字段回答支持状态、方式、运输时间和预估费用，并附 Shipping Info 链接；只有客户问及关税时才补充进口关税规则。'],
                ['condition' => '查询成功但 supported=false 或 methods 为空', 'outcome' => '说明当前不支持配送到该国家或地区，并附 Shipping Info 链接。'],
                ['condition' => '查询失败、数据缺失或国家无法标准化', 'outcome' => '不猜测配送范围、费用或时效，转人工确认；若客户只问进口关税，仍可按已确认政策回答。'],
                ['condition' => '客户询问 VAT、销售税、地方税、清关文件或特殊税费', 'outcome' => '不要将进口关税政策泛化到其他税费，转人工确认。'],
                ['condition' => '客户询问总到货时间或活动日期可达性', 'outcome' => '转入 ask_delivery_time，读取商品 ETA；不得只用 transit_days 承诺到货。'],
            ]),
            'reply_strategy' => $json([
                ['stage' => '识别问题范围', 'goal' => '只回答客户实际询问的配送字段', 'content_requirements' => ['区分国家支持、配送方式、发货后运输时效、预估费用与进口关税']],
                ['stage' => '查询当前事实', 'goal' => '取得目的地当前配送选项', 'content_requirements' => ['调用 GET /shipping/options?country={country_code}', '读取 supported、country_name、currency 与 methods 列表']],
                ['stage' => '构建回复', 'goal' => '给出简洁且可核实的结论', 'content_requirements' => ['运费注明为预估值', '运输时间注明不含制作周期', '使用当前站点 Shipping Info 链接']],
                ['stage' => '按需说明关税', 'goal' => '回答进口关税问题且不扩大承诺', 'content_requirements' => ['商家承担进口关税', 'VAT、销售税、地方税等特殊税费不在该结论内']],
                ['stage' => '风险检查', 'goal' => '避免无事实承诺', 'content_requirements' => ['接口失败、特殊税费、总 ETA 或保证性要求时进入相应流程或转人工']],
            ]),
            'reply_templates' => $json([
                ['conditions' => ['客户只询问是否配送且查询成功'], 'body' => 'Yes, we currently ship to {{ country_name }}. You can review the available shipping information at {{ site.public_base_url }}/shipping-info.html.'],
                ['conditions' => ['客户询问配送方式、运输时间或费用且查询成功'], 'body' => 'Yes, we currently ship to {{ country_name }}. Available options: {{ shipping_options }}. The listed time is transit time after dispatch, and the shipping cost is an estimate; the final amount will be shown at checkout. You can also review {{ site.public_base_url }}/shipping-info.html for details.'],
                ['conditions' => ['客户询问进口关税'], 'body' => 'We cover the import duties for your order, so you will not need to pay additional import duty upon delivery. This does not include VAT, sales tax, local tax, or other special charges.'],
                ['conditions' => ['国家当前不支持配送'], 'body' => 'We’re sorry, but we do not currently have a shipping option for {{ country_name }}. You can review the latest supported destinations at {{ site.public_base_url }}/shipping-info.html.'],
                ['conditions' => ['客户未提供国家且需要查询配送数据'], 'body' => 'Which country or region would you like the order shipped to? We’ll check the available methods, estimated transit time, and cost for you.'],
            ]),
            'action_steps' => $json([
                '识别客户实际询问的是国家支持、配送方式、运输时间、预估费用还是进口关税。',
                '需要目的地数据时，将国家或地区标准化为两位 country_code。',
                '调用 GET /shipping/options?country={country_code}，请求中不传 site 参数。',
                '仅按客户所问字段组织回复，使用当前站点的 public_base_url 生成 Shipping Info 链接。',
                '如客户询问总到货时间，转入商品 ETA 流程；如涉及特殊税费或要求保证，转人工。',
            ]),
            'prohibited_claims' => $json([
                '不得使用历史问答中的固定运费、配送国家或时效作为当前事实。',
                '不得把 estimated_cost 描述为最终精确收费。',
                '不得把 transit_days 描述为包含商品制作周期的总到货时间。',
                '不得将进口关税政策扩张为 VAT、销售税、消费税、地方税、支付手续费或其他费用均由商家承担。',
                '不得对接口未确认支持的国家声明可以配送。',
                '不得保证在指定日期前到货。',
            ]),
            'escalation_rules' => $json([
                '配送查询接口失败、超时、数据缺失或数据过期。',
                '客户提供的国家或地区无法标准化。',
                '客户询问 VAT、销售税、消费税、地方税、清关文件或其他特殊费用。',
                '客户要求保证最终结账金额或指定日期前到货。',
                '客户明确要求人工客服。',
            ]),
        ];
    }
};
