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
            $candidate = DB::table('product_question_policy_candidates')
                ->where('id', 24)
                ->where('scenario_key', 'ask_exchange_policy')
                ->first();
            if ($candidate === null || $candidate->review_status === 'confirmed') {
                return;
            }

            $now = now();
            $reviewerId = DB::table(config('admin.database.users_table'))->min('id');
            $content = $this->content();
            DB::table('product_question_policy_candidates')->where('id', $candidate->id)->update([
                ...$content,
                'conflicts' => '[]',
                'open_questions' => '[]',
                'confidence' => 0.9500,
                'review_status' => 'confirmed',
                'review_notes' => '业务确认：售前换码咨询按当前退货政策的对应条款回复。网站不提供 direct exchange；符合条件的商品按退货审批后重新下单处理。已根据政策依据移除未经支持的 10% restocking fee 和 72 小时退款表述。',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ]);

            $candidate = DB::table('product_question_policy_candidates')->where('id', $candidate->id)->first();
            $siteKey = DB::table('commerce_sites')->where('id', $candidate->commerce_site_id)->value('site_key');
            if (! is_string($siteKey) || $siteKey === '') {
                return;
            }

            $key = 'qa_'.$siteKey.'_ask_exchange_policy';
            $existing = DB::table('knowledge_articles')->where('key', $key)->first();
            $articleContent = $content;
            unset($articleContent['definition']);
            $articleValues = [
                'title' => $candidate->title,
                'knowledge_type' => 'policy',
                'status' => 'verified',
                'site_key' => $siteKey,
                ...$articleContent,
                'source_notes' => '问答策略 #24，证据站点 '.$siteKey.'，人工确认；证据 '.count($this->jsonList($candidate->evidence_question_ids)).' 条。完整条款依据 docs/policies/dressafford-cancellation-and-return-policy.md 的退货政策部分。',
                'version' => ((int) ($existing->version ?? 0)) + 1,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ];
            if ($existing === null) {
                $articleId = DB::table('knowledge_articles')->insertGetId([
                    'key' => $key,
                    ...$articleValues,
                    'created_at' => $now,
                ]);
            } else {
                DB::table('knowledge_articles')->where('id', $existing->id)->update($articleValues);
                $articleId = (int) $existing->id;
            }

            DB::table('product_question_strategy_evidence')->where('knowledge_article_id', $articleId)->delete();
            foreach ($this->jsonList($candidate->evidence_question_ids) as $questionId) {
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

            DB::table('product_question_policy_candidates')->where('id', $candidate->id)->update([
                'knowledge_article_id' => $articleId,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        // Human review decisions and published knowledge are intentionally not reversed.
    }

    /** @return array<string, string> */
    private function content(): array
    {
        return [
            'title' => '售前退货与换码政策咨询',
            'definition' => '客户在购买前询问商品不合身时能否换码、退货、退款，以及适用条件、时间窗口、费用和处理方式。',
            'applicability' => '适用于售前解释退货和换码政策。实际退货申请、质量问题、错发少件、退款进度、取消订单或例外审批必须进入对应售后流程并核实订单事实。',
            'required_facts' => $this->json([
                '客户具体询问的条款：换码方式、资格、时间窗口、商品状态、费用或退款时效',
                '商品是否为定制、礼品、孕妇装或个人护理类商品',
                '商品是否保持吊牌完整、未穿、未洗、未改、无损且无香水气味',
                '签收日期、退货批准日期和寄出日期（仅实际退货申请需要）',
                '当前站点的 site.public_base_url 与 site.customer_service_email',
            ]),
            'policy_rules' => $this->json([
                '按客户实际询问的退货政策条款针对性回复，不必在每次售前问答中罗列全部政策。',
                '不提供 direct exchange；换码按符合条件的原商品退货并另行下新订单处理，新订单遵循当时的制作和配送时间。',
                '客户须在收到商品后 14 天内联系 {{ site.customer_service_email }} 申请退货批准；未经客服事先批准的退货不接受。',
                '获得批准后，客户须在 7 天内通过当地邮局寄出、填写 Return Form，并将退件追踪号告知客服；UPS、DHL 和 FedEx 不接受。',
                '退件必须保留吊牌、未穿、未洗、未改、无损且没有香水气味；不符合时可拒绝退款。',
                '所有定制商品、礼品类商品、孕妇装及个人护理类商品不可退换。',
                '符合条件的退货通常退还退回商品的商品价；原运费、加急费和政府收取的关税不退。',
                '退件到仓并验收后才处理退款；政策页面提示银行处理退款请求需要 8–10 天，但不得承诺具体到账日。',
                '完整政策链接使用 {{ site.public_base_url }}/article/return-policy.html。',
            ]),
            'decision_tree' => $this->json([
                ['condition' => '客户只问尺码不合适时能否换码', 'outcome' => '说明不提供 direct exchange；符合退货条件的商品可申请退货，然后按需要重新下单。'],
                ['condition' => '客户询问申请和寄回时间', 'outcome' => '说明签收后 14 天内联系申请，获批后 7 天内按要求寄出。'],
                ['condition' => '客户询问商品状态要求', 'outcome' => '说明吊牌、穿洗、修改、损坏和气味要求。'],
                ['condition' => '客户询问哪些商品不能退换', 'outcome' => '列出定制、礼品、孕妇装和个人护理类商品。'],
                ['condition' => '客户询问退款范围或到账时间', 'outcome' => '说明通常退商品价，原运费、加急费和政府关税不退；验收后处理退款，银行处理请求需要 8–10 天。'],
                ['condition' => '客户询问新尺码多久收到', 'outcome' => '说明新尺码需要另行下单，并转入当前商品 ETA 策略评估制作和配送时间。'],
                ['condition' => '客户已经收到商品并要求实际退货，或涉及质量、错发、少件、例外', 'outcome' => '要求通过客服邮箱提交订单号和情况，转人工售后处理，不在公开问答中批准退货或承诺费用。'],
            ]),
            'reply_strategy' => $this->json([
                ['stage' => '识别条款', 'goal' => '只回答客户实际关心的政策点', 'content_requirements' => ['区分换码方式、退货资格、时间、商品状态、费用和退款时效']],
                ['stage' => '给出直接结论', 'goal' => '先回答能否以及如何处理', 'content_requirements' => ['无 direct exchange', '符合条件时 return + new order']],
                ['stage' => '补充适用限制', 'goal' => '避免遗漏决定资格的关键条件', 'content_requirements' => ['按问题补充时间窗口、商品状态或不可退换类别']],
                ['stage' => '提供官方入口', 'goal' => '让客户查看完整且当前的政策', 'content_requirements' => ['附 Return Policy 链接', '实际申请提供客服邮箱']],
                ['stage' => '售后边界检查', 'goal' => '不在售前问答中作出个案批准或退款承诺', 'content_requirements' => ['实际订单、质量争议和例外转人工']],
            ]),
            'reply_templates' => $this->json([
                ['conditions' => ['客户询问尺码不合适能否换码或换货'], 'body' => 'We do not offer direct exchanges. If an eligible item does not fit, you may request a return and place a separate new order for the size you need. Please contact us within 14 days of receiving the item to request return approval. You can review the complete policy at {{ site.public_base_url }}/article/return-policy.html.'],
                ['conditions' => ['客户询问退货申请和寄回时间'], 'body' => 'Please contact us at {{ site.customer_service_email }} within 14 days of receiving the item to request return approval. After approval, the item must be shipped from a local post office within 7 days using the Return Form, and the return tracking number must be emailed to us. UPS, DHL, and FedEx are not accepted for the return shipment.'],
                ['conditions' => ['客户询问退件必须保持什么状态'], 'body' => 'The returned item must have its tags attached and remain unworn, unwashed, unaltered, undamaged, and free of perfume or other odors. A refund may be declined if the returned item does not meet these conditions.'],
                ['conditions' => ['客户询问定制或特殊类别商品是否可以退换'], 'body' => 'Customized items, gift items, maternity dresses, and personal-care items such as lingerie or swimwear are not eligible for return or exchange under the current policy.'],
                ['conditions' => ['客户询问退款范围或退款时间'], 'body' => 'For an eligible approved return, the refundable amount generally covers the returned item price. Original shipping fees, rush fees, and customs duties charged by the government are non-refundable. The refund is processed after the returned item is delivered and inspected, and the policy states that banks may need 8–10 days to process the refund request.'],
                ['conditions' => ['客户已经收到商品并要求实际退货或提出质量问题'], 'body' => 'Please email {{ site.customer_service_email }} with your order number and details of the issue so our customer-service team can review the order and provide the correct return instructions. Please do not send the item back before receiving approval and the Return Form.'],
            ]),
            'action_steps' => $this->json([
                '识别客户询问的是换码方式、资格、时间窗口、商品状态、费用还是退款时效。',
                '从 verified 退货政策中选取直接相关的条款，先给结论，再补充必要限制。',
                '售前一般咨询附 {{ site.public_base_url }}/article/return-policy.html。',
                '实际退货申请或个案判断引导客户通过 {{ site.customer_service_email }} 提交订单号和情况。',
                '客户询问新订单到货时间时，调用当前商品 ETA 策略，不复用历史固定时效。',
                '质量问题、错发、少件、超期、例外或费用争议转人工处理。',
            ]),
            'prohibited_claims' => $this->json([
                '不得声称提供 direct exchange 或有现货可直接换码。',
                '不得收取或承诺免除政策依据中未规定的 10% restocking fee。',
                '不得使用历史回复中的 72 小时作为退款处理或到账承诺。',
                '不得对定制、礼品、孕妇装或个人护理类商品承诺可退换。',
                '不得将原运费、加急费或政府关税描述为可退。',
                '不得保证退款在 8–10 天内到账；该时间仅是银行处理退款请求的政策提示。',
                '不得在公开商品问答中批准某张订单退货、提供退货地址或披露订单信息。',
                '不得从一般退货政策推导质量问题、错发少件或特殊争议的费用承担。',
            ]),
            'escalation_rules' => $this->json([
                '客户已经收到商品并正式申请退货，需要核实订单、签收、商品类型和状态。',
                '客户声称质量问题、错发、少件、与描述不符或要求卖家承担退货运费。',
                '超过 14 天申请窗口、超过批准后 7 天寄回窗口，或未经批准已自行寄回。',
                '商品已穿、已洗、已改、损坏、吊牌缺失或存在气味争议。',
                '涉及定制商品与质量争议的优先级、退件丢失、拒收或非指定承运方式。',
                '客户要求退款金额、到账日期、特批换货、赔偿或其他政策例外。',
            ]),
        ];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
};
