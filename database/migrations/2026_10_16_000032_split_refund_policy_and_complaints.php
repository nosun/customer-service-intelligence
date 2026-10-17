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
            $returnPolicy = DB::table('product_question_policy_candidates')->where('id', 15)->first();
            $complaintPolicy = DB::table('product_question_policy_candidates')->where('id', 26)->first();
            if ($returnPolicy === null || $complaintPolicy === null || $complaintPolicy->review_status === 'confirmed') {
                return;
            }

            $now = now();
            $reviewerId = DB::table(config('admin.database.users_table'))->min('id');
            $returnEvidence = array_values(array_unique([
                ...$this->jsonList($returnPolicy->evidence_question_ids),
                816,
            ]));
            $complaintEvidence = array_values(array_filter(
                $this->jsonList($complaintPolicy->evidence_question_ids),
                fn (mixed $questionId): bool => (int) $questionId !== 816,
            ));

            $returnContent = $this->returnPolicyContent();
            DB::table('product_question_policy_candidates')->where('id', $returnPolicy->id)->update([
                ...$returnContent,
                'evidence_question_ids' => $this->json($returnEvidence),
                'conflicts' => '[]',
                'open_questions' => '[]',
                'confidence' => 0.9500,
                'review_notes' => '已按正式退货政策依据复核，并吸收原 Policy 26 中的售前退货证据 #816；移除未经政策支持的 10% restocking fee 和 security seal 表述。',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ]);
            $this->updatePublishedArticle(
                $returnPolicy,
                $returnContent,
                $returnEvidence,
                '通用退货政策；已按 docs/policies/dressafford-cancellation-and-return-policy.md 复核并加入售前证据 #816。',
                $reviewerId,
                $now,
            );

            DB::table('product_question_analyses')->where('product_question_id', 816)->update([
                'intent_key' => 'ask_return_policy',
                'review_status' => 'corrected',
                'review_notes' => '该问题是购买前的一般退货与退款范围咨询，不是已发生的售后退款投诉。',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ]);

            $complaintContent = $this->complaintContent();
            DB::table('product_question_policy_candidates')->where('id', $complaintPolicy->id)->update([
                ...$complaintContent,
                'evidence_question_ids' => $this->json($complaintEvidence),
                'conflicts' => '[]',
                'open_questions' => '[]',
                'confidence' => 0.9500,
                'review_status' => 'confirmed',
                'review_notes' => '业务确认：Policy 26 仅处理已发生的商品不符、质量、尺寸责任、退款或补偿投诉。公开回复只作安抚和渠道引导，不认定责任、不批准退款或补偿；一般退货与换码条款统一使用 Policy 15。',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ]);

            $complaintPolicy = DB::table('product_question_policy_candidates')->where('id', $complaintPolicy->id)->first();
            $siteKey = DB::table('commerce_sites')->where('id', $complaintPolicy->commerce_site_id)->value('site_key');
            if (! is_string($siteKey) || $siteKey === '') {
                return;
            }

            $articleId = $this->publishArticle(
                'qa_'.$siteKey.'_ask_refund',
                $siteKey,
                $complaintPolicy,
                $complaintContent,
                $complaintEvidence,
                '问答策略 #26，证据站点 '.$siteKey.'，人工确认；仅用于售后投诉的安全首轮响应，证据 '.count($complaintEvidence).' 条。',
                $reviewerId,
                $now,
            );
            DB::table('product_question_policy_candidates')->where('id', $complaintPolicy->id)->update([
                'knowledge_article_id' => $articleId,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        // Human review decisions and published knowledge are intentionally not reversed.
    }

    private function updatePublishedArticle(
        object $candidate,
        array $content,
        array $evidence,
        string $sourceNotes,
        mixed $reviewerId,
        mixed $now,
    ): void {
        if ($candidate->knowledge_article_id === null) {
            return;
        }

        $article = DB::table('knowledge_articles')->where('id', $candidate->knowledge_article_id)->first();
        if ($article === null) {
            return;
        }

        $articleContent = $content;
        unset($articleContent['definition']);
        DB::table('knowledge_articles')->where('id', $article->id)->update([
            ...$articleContent,
            'source_notes' => $sourceNotes.' 证据 '.count($evidence).' 条。',
            'version' => ((int) $article->version) + 1,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => $now,
            'updated_at' => $now,
        ]);
        $this->replaceEvidence((int) $article->id, $evidence, $now);
    }

    private function publishArticle(
        string $key,
        string $siteKey,
        object $candidate,
        array $content,
        array $evidence,
        string $sourceNotes,
        mixed $reviewerId,
        mixed $now,
    ): int {
        $existing = DB::table('knowledge_articles')->where('key', $key)->first();
        $articleContent = $content;
        unset($articleContent['definition']);
        $values = [
            'title' => $content['title'],
            'knowledge_type' => 'process',
            'status' => 'verified',
            'site_key' => $siteKey,
            ...$articleContent,
            'source_notes' => $sourceNotes,
            'version' => ((int) ($existing->version ?? 0)) + 1,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => $now,
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $articleId = DB::table('knowledge_articles')->insertGetId([
                'key' => $key,
                ...$values,
                'created_at' => $now,
            ]);
        } else {
            DB::table('knowledge_articles')->where('id', $existing->id)->update($values);
            $articleId = (int) $existing->id;
        }
        $this->replaceEvidence($articleId, $evidence, $now);

        return $articleId;
    }

    private function replaceEvidence(int $articleId, array $questionIds, mixed $now): void
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
    private function returnPolicyContent(): array
    {
        return [
            'title' => '通用退货政策咨询',
            'definition' => '客户在购买前或购买后询问一般退货资格、申请和寄回时间、商品状态、退款范围及不可退换类别。',
            'applicability' => '适用于一般退货与换码政策解释；统一使用 ask_return_policy。已发生的质量、错发、退款、补偿或投诉使用 ask_refund 并转人工售后。',
            'required_facts' => $this->json([
                '客户询问的一般退货条款',
                '商品是否属于定制、礼品、孕妇装或个人护理类',
                '实际申请时的签收、批准、寄出时间和商品状态',
                '当前站点的 site.public_base_url 与 site.customer_service_email',
            ]),
            'policy_rules' => $this->json([
                '客户须在收到商品后 14 天内联系 {{ site.customer_service_email }} 申请退货批准；未经批准的退货不接受。',
                '获得批准后须在 7 天内通过当地邮局寄出、填写 Return Form 并邮件提供退件追踪号；UPS、DHL 和 FedEx 不接受。',
                '退件须保留吊牌、未穿、未洗、未改、无损且没有香水气味。',
                '定制商品、礼品类商品、孕妇装和个人护理类商品不可退换。',
                '符合条件时通常退还退回商品的商品价；原运费、加急费和政府关税不退。',
                '不支持多买一留一，也不提供直接换货。',
                '退件到仓并验收后处理退款；银行处理退款请求需要 8–10 天，但不得承诺到账日期。',
                '完整政策链接为 {{ site.public_base_url }}/article/return-policy.html。',
            ]),
            'decision_tree' => $this->json([
                ['condition' => '客户询问一般退货资格或能否全额退订单总额', 'outcome' => '说明资格和商品状态要求，并区分商品价与不退的原运费、加急费和政府关税。'],
                ['condition' => '客户询问时间窗口或寄回方式', 'outcome' => '说明签收后 14 天内申请、批准后 7 天内通过当地邮局寄出及不接受的承运商。'],
                ['condition' => '客户询问定制或特殊类别商品', 'outcome' => '说明不可退换类别。'],
                ['condition' => '客户询问换码', 'outcome' => '按 ask_return_policy 说明不提供 direct exchange；符合条件时退回原商品并重新下单。'],
                ['condition' => '客户已报告质量、错发、少件、退款或补偿争议', 'outcome' => '转入 ask_refund，不用一般政策判断责任或方案。'],
            ]),
            'reply_strategy' => $this->json([
                ['stage' => '识别条款', 'goal' => '只回答客户实际询问的政策点', 'content_requirements' => ['资格、时间、状态、费用或类别']],
                ['stage' => '说明边界', 'goal' => '避免把一般政策用于个案争议', 'content_requirements' => ['投诉和退款个案转 ask_refund']],
                ['stage' => '提供入口', 'goal' => '给出完整政策和申请渠道', 'content_requirements' => ['Return Policy 链接', '客服邮箱']],
            ]),
            'reply_templates' => $this->json([
                ['conditions' => ['客户询问一般退货资格和退款范围'], 'body' => 'Eligible items may be returned after approval if you contact us within 14 days of receiving them. The item must have its tags attached and remain unworn, unwashed, unaltered, undamaged, and free of perfume or other odors. The refundable amount generally covers the returned item price; original shipping fees, rush fees, and customs duties charged by the government are non-refundable. Please review {{ site.public_base_url }}/article/return-policy.html for the complete policy.'],
                ['conditions' => ['客户询问申请和寄回流程'], 'body' => 'Please email {{ site.customer_service_email }} within 14 days of receiving the item to request approval. After approval, ship it from a local post office within 7 days using the Return Form and email us the tracking number. UPS, DHL, and FedEx are not accepted for the return shipment.'],
                ['conditions' => ['客户询问不可退换类别'], 'body' => 'Customized items, gift items, maternity dresses, and personal-care items such as lingerie or swimwear are not eligible for return or exchange under the current policy.'],
            ]),
            'action_steps' => $this->json([
                '识别客户询问的通用退货条款。',
                '按 verified 条款针对性回复并附当前站点 Return Policy 链接。',
                '实际申请引导至客服邮箱；质量、错发、少件、退款或补偿争议转 ask_refund。',
            ]),
            'prohibited_claims' => $this->json([
                '不得声称存在政策未规定的 10% restocking fee。',
                '不得使用 security seal 替代正式政策中的吊牌和商品状态要求。',
                '不得保证退款到账日期。',
                '不得用一般退货政策判断质量争议责任、退货运费或补偿金额。',
            ]),
            'escalation_rules' => $this->json([
                '客户已经正式申请退货或提供具体订单。',
                '涉及质量、错发、少件、损坏、定制争议或费用承担。',
                '超过政策时间窗口、未经批准寄回或使用非指定承运方式。',
            ]),
        ];
    }

    /** @return array<string, string> */
    private function complaintContent(): array
    {
        return [
            'title' => '售后商品问题投诉与退款诉求初步响应',
            'definition' => '客户已收到商品，并因商品不符、尺寸或质量问题提出退款、补偿或投诉；公开回复仅用于安抚和引导进入人工售后核实。',
            'applicability' => '适用于已发生的商品不符、错发、质量、尺寸责任、退款、补偿、长期未回复或外部投诉。不适用于购买前的一般退货政策咨询。',
            'required_facts' => $this->json([
                '客户投诉的问题类型和希望获得的处理',
                '订单号、下单邮箱和相关图片或说明（通过私密渠道收集）',
                '订单、生产、商品和历史沟通记录（仅人工售后核实）',
                '当前站点的 site.customer_service_email',
            ]),
            'policy_rules' => $this->json([
                '公开首轮回复只表达理解和歉意、说明需要人工核实，并引导客户联系 {{ site.customer_service_email }}。',
                '不得在商品问答中判断责任、批准退款或承诺补偿、退货运费和处理时限。',
                '请客户通过私密邮件提供订单号、下单邮箱、问题说明及必要图片；不得要求其在公开问答中发布个人信息。',
                '一般退货与换码条款统一使用 ask_return_policy；不得把一般政策直接套用于质量、错发或责任争议。',
                '先按英文标准模板完成事实和风险检查，再按客户语言翻译。',
            ]),
            'decision_tree' => $this->json([
                ['condition' => '客户投诉商品与图片或订购款式不符、错发或质量问题', 'outcome' => '致歉并引导私密提交订单信息与对比图片，由售后核实；不承认责任或退款。'],
                ['condition' => '客户主张尺寸或测量责任并要求裁缝费、部分退款或补偿', 'outcome' => '表达遗憾并说明需核对订单、尺寸和生产记录；不承诺金额。'],
                ['condition' => '客户抱怨长期没有回复或情绪激动', 'outcome' => '承认其不便并提供客服邮箱，标记人工高优先级；不编造响应 SLA。'],
                ['condition' => '客户已发起支付争议、平台投诉、报警或法律行动', 'outcome' => '使用中性非对抗回复并立即转人工售后/合规；不评价外部程序结果。'],
                ['condition' => '客户只是购买前询问一般退货或换码政策', 'outcome' => '转入 ask_return_policy。'],
            ]),
            'reply_strategy' => $this->json([
                ['stage' => '安抚', 'goal' => '承认客户的不便和担忧', 'content_requirements' => ['简洁道歉或遗憾', '不承认责任']],
                ['stage' => '说明边界', 'goal' => '解释公开渠道无法裁定个案', 'content_requirements' => ['需要核实订单和证据', '不承诺退款、补偿或时限']],
                ['stage' => '引导私密联系', 'goal' => '让人工售后取得必要资料', 'content_requirements' => ['提供客服邮箱', '订单号、下单邮箱、问题说明和图片']],
                ['stage' => '内部升级', 'goal' => '按风险交给人工处理', 'content_requirements' => ['退款补偿、外部投诉、健康安全或长期未回复升级']],
            ]),
            'reply_templates' => $this->json([
                ['conditions' => ['客户投诉商品不符、错发或质量问题'], 'body' => 'We are sorry that the item did not meet your expectations. This case needs to be reviewed against the order and the item received. Please email {{ site.customer_service_email }} with your order number, the email address used at checkout, a description of the issue, and clear photos. Our customer-service team will review the information before confirming the available resolution.'],
                ['conditions' => ['客户要求部分退款、裁缝费或其他补偿'], 'body' => 'We are sorry for the difficulty this has caused. A refund or compensation request must be reviewed against the order, measurements, and production records. Please send the order number, checkout email, relevant photos or measurements, and the requested resolution to {{ site.customer_service_email }}. We cannot confirm liability or an amount before the review is completed.'],
                ['conditions' => ['客户情绪激动、长期未获回复或已发起外部投诉'], 'body' => 'We are very sorry about your experience and understand your concern. Please email your order number, checkout email, and a summary of the issue to {{ site.customer_service_email }} so the appropriate team can review the case. We cannot confirm liability, a refund, or compensation in a public reply before the case is investigated.'],
            ]),
            'action_steps' => $this->json([
                '识别这是已发生的售后投诉，而不是一般退货政策咨询。',
                '使用英文标准模板表达歉意和核实边界。',
                '引导客户通过 {{ site.customer_service_email }} 私密提交订单号、下单邮箱、问题说明和必要图片。',
                '不得在公开商品问答中收集或复述个人信息。',
                '将退款、补偿、质量、错发、外部投诉或长期未回复标记为人工售后处理。',
                '如需非英语回复，在英文内容审核完成后翻译。',
            ]),
            'prohibited_claims' => $this->json([
                '不得在核实前承认商品错误、生产错误、测量责任或其他责任归属。',
                '不得承诺全额退款、部分退款、赔偿、裁缝费、退货运费或具体金额。',
                '不得承诺客服将在具体小时或工作日内回复，除非存在已验证 SLA。',
                '不得在公开回复中披露或要求客户公开订单号、邮箱、地址、电话或付款信息。',
                '不得用一般退货政策否定质量、错发或商品不符投诉。',
                '不得评价客户的支付争议、平台投诉、报警或法律行动结果。',
            ]),
            'escalation_rules' => $this->json([
                '客户要求退款、补偿、承担裁缝费或退货运费。',
                '涉及商品不符、错发、质量、尺寸责任或定制商品争议。',
                '客户连续未获回复、婚期临近或情绪强烈升级。',
                '客户已发起支付争议、平台投诉、报警或法律行动。',
                '涉及人身伤害、安全、健康、欺诈或合规风险。',
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
