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
                ->where('id', 25)
                ->where('scenario_key', 'ask_order_status')
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
                'review_notes' => '业务确认：仅寻找查询入口时引导至当前站点 /order/status；咨询具体订单进度时，使用客户邮箱或订单号读取 console-new 的订单、生产工单和物流事实，并建议通过站点客服邮箱获得进一步支持。订单明细只能通过私密回复提供。',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ]);

            $candidate = DB::table('product_question_policy_candidates')->where('id', $candidate->id)->first();
            $siteKey = DB::table('commerce_sites')->where('id', $candidate->commerce_site_id)->value('site_key');
            if (! is_string($siteKey) || $siteKey === '') {
                return;
            }

            $key = 'qa_'.$siteKey.'_ask_order_status';
            $existing = DB::table('knowledge_articles')->where('key', $key)->first();
            $articleContent = $content;
            unset($articleContent['definition']);
            $articleValues = [
                'title' => $candidate->title,
                'knowledge_type' => 'process',
                'status' => 'verified',
                'site_key' => $siteKey,
                ...$articleContent,
                'source_notes' => '问答策略 #25，证据站点 '.$siteKey.'，人工确认并按 console-new Admin API 合同复核；证据 '.count($this->jsonList($candidate->evidence_question_ids)).' 条。',
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
            'title' => '订单查询入口与售中订单进度查询',
            'definition' => '区分客户只是寻找订单自助查询入口，还是要求查询已付款订单的具体生产或物流进度，并按不同路径回复。',
            'applicability' => '适用于已下单客户查询订单入口、订单处理阶段、生产进度或物流状态。不适用于售前 ETA、退换货、取消、退款或修改订单。',
            'required_facts' => $this->json([
                '客户意图：仅寻找查询入口，或要求查询具体订单进度',
                '当前站点的 site.public_base_url 与 site.customer_service_email',
                '用于定位订单的客户邮箱或订单号',
                'GET /orders?customer_email={email} 返回的最近已付款订单及站点归属',
                'GET /orders/{order_no} 返回的订单状态、订单商品、预计日期与物流信息',
                'GET /work-orders?order_no={order_no} 返回的 work_process_text 与 product_status_text',
            ]),
            'policy_rules' => $this->json([
                '客户只是询问在哪里查看订单、未要求查询具体状态时，不调用后台接口；直接提供 {{ site.public_base_url }}/order/status，说明输入订单号和下单邮箱即可查询。',
                '客户要求查询具体订单进度时，优先用问答记录中的客户邮箱调用 console-new GET /orders?customer_email={email} 查询最近的已付款订单。',
                '只能使用与当前问答站点匹配的订单；无法可靠确认站点归属时转人工，不得泄露其他站点的订单。',
                '没有匹配订单时，请客户核对订单号和下单邮箱，并建议通过 {{ site.customer_service_email }} 联系客服。',
                '有多个匹配订单时，不得猜测目标订单；请客户通过私密渠道提供订单号。',
                '定位唯一订单后，调用 GET /orders/{order_no} 读取订单与物流状态，并调用 GET /work-orders?order_no={order_no} 读取生产状态。',
                '订单状态、生产状态、物流状态必须来自本次 API 查询，不使用历史回复中的日期、阶段或追踪号。',
                '订单专属信息只能通过私密回复提供；不得在公开商品问答中展示完整订单号、邮箱、地址、电话、追踪号或订单内容。',
                '回复结尾提供 {{ site.customer_service_email }}，建议客户需要更全面支持时联系人工客服。',
            ]),
            'decision_tree' => $this->json([
                ['condition' => '客户仅询问订单查询入口或如何自行查看订单', 'outcome' => '提供当前站点 /order/status 页面，说明使用订单号和下单邮箱查询；无需调用 console-new。'],
                ['condition' => '客户要求查询具体订单进度，且有客户邮箱', 'outcome' => '按邮箱查询最近已付款订单，并过滤到当前站点。'],
                ['condition' => '当前站点没有匹配订单', 'outcome' => '请客户核对订单号和下单邮箱，并通过站点客服邮箱联系人工支持；不得断言客户没有下单。'],
                ['condition' => '当前站点存在多个可能订单', 'outcome' => '通过私密渠道请求订单号，不得披露订单列表或猜测目标订单。'],
                ['condition' => '唯一订单已定位或客户提供了可验证的订单号', 'outcome' => '读取订单详情、生产工单和物流状态，形成私密回复。'],
                ['condition' => '订单仍在生产且尚未发货', 'outcome' => '说明当前订单和生产阶段；只有 API 返回预计日期时才可作为估计值说明，并注明不是保证日期。'],
                ['condition' => '订单已发货', 'outcome' => '说明承运商和当前物流状态；仅在私密渠道提供追踪号或追踪链接。'],
                ['condition' => 'API 失败、状态互相冲突、生产异常或物流异常', 'outcome' => '不猜测，转人工并提供站点客服邮箱。'],
            ]),
            'reply_strategy' => $this->json([
                ['stage' => '识别意图', 'goal' => '区分自助入口与具体进度查询', 'content_requirements' => ['只找入口时直接给页面', '具体状态查询才调用后台']],
                ['stage' => '安全定位订单', 'goal' => '使用最少信息找到当前站点订单', 'content_requirements' => ['按邮箱查询最近订单', '核对站点归属', '多订单时私密请求订单号']],
                ['stage' => '读取当前事实', 'goal' => '取得订单、生产和物流三个维度的最新状态', 'content_requirements' => ['读取订单详情', '读取生产工单状态', '读取物流和追踪状态']],
                ['stage' => '生成私密回复', 'goal' => '清楚说明当前情况且不泄露个人信息', 'content_requirements' => ['概括订单状态', '概括生产状态', '概括物流状态', '预计日期明确为估计值']],
                ['stage' => '提供后续支持', 'goal' => '让客户可获得更全面的人工协助', 'content_requirements' => ['附 {{ site.customer_service_email }}', '异常或修改请求转人工']],
            ]),
            'reply_templates' => $this->json([
                ['conditions' => ['客户只询问订单查询入口'], 'body' => 'You can check your order at {{ site.public_base_url }}/order/status. Enter your order number and the email address used at checkout. If you need further help, please contact us at {{ site.customer_service_email }}.'],
                ['conditions' => ['已私密定位订单，订单仍在生产且尚未发货'], 'body' => 'We checked your order {{ order_number }}. Its current order status is {{ order_status }}, and the production status is {{ production_status }}. It has not shipped yet. {{ estimated_date_statement }} For more detailed assistance, please contact us at {{ site.customer_service_email }}.'],
                ['conditions' => ['已私密定位订单且订单已发货'], 'body' => 'We checked your order {{ order_number }}. It has shipped with {{ carrier }}, and the current logistics status is {{ logistics_status }}. Your tracking number is {{ tracking_number }}. For more detailed assistance, please contact us at {{ site.customer_service_email }}.'],
                ['conditions' => ['按邮箱未找到当前站点订单'], 'body' => 'We could not locate a matching order with the available information. Please verify the order number and the email address used at checkout, or check at {{ site.public_base_url }}/order/status. You can also contact us at {{ site.customer_service_email }} for further assistance.'],
                ['conditions' => ['按邮箱找到多个订单，无法确定客户所指订单'], 'body' => 'We found more than one possible order. For your privacy, please email {{ site.customer_service_email }} with the order number and the email address used at checkout so our team can check the correct order.'],
            ]),
            'action_steps' => $this->json([
                '判断客户只是寻找查询入口，还是要求获取具体订单状态。',
                '只找入口时回复 {{ site.public_base_url }}/order/status，并说明输入订单号和下单邮箱。',
                '具体进度查询时，调用 GET /orders?customer_email={email}&page=1&page_size=10 查询最近已付款订单。',
                '核对订单属于当前问答站点；零个或多个候选时按决策树追问或转人工。',
                '定位订单后调用 GET /orders/{order_no} 获取订单与物流信息。',
                '调用 GET /work-orders?order_no={order_no}&page=1&page_size=100 获取生产状态。',
                '只提取可对客户公开的订单阶段、生产阶段、预计日期、承运商和物流状态，不引用内部备注。',
                '订单专属回复必须使用私密渠道或 visible=false，最后附 {{ site.customer_service_email }}。',
            ]),
            'prohibited_claims' => $this->json([
                '不得在未调用后台接口时声称订单处于某一生产或物流阶段。',
                '不得把历史问答中的预计日期、订单号、追踪号或状态用于其他客户。',
                '不得在公开商品问答中展示订单号、邮箱、地址、电话、追踪号或订单商品明细。',
                '不得在多个候选订单中猜测客户所指订单。',
                '不得把预计完成、发货或到达日期表述为保证日期。',
                '不得向客户披露内部备注、供应商、成本、质检备注或后台异常详情。',
                '不得仅凭 Made、生产完成或生成面单就声称包裹已经由承运商收件。',
                '不得未经授权承诺修改、取消、退款、折扣或赔偿。',
            ]),
            'escalation_rules' => $this->json([
                'console-new 未配置、接口失败、超时或返回数据不完整。',
                '无法确认订单属于当前问答站点，或客户邮箱匹配多个订单且没有订单号。',
                '订单状态、生产工单状态和物流状态之间存在冲突。',
                '生产暂停、异常、延期，或物流长时间无更新、退回、丢失。',
                '客户要求修改地址、商品、尺码、取消订单、退款、折扣或赔偿。',
                '客户要求公开回复订单详情，或身份信息无法安全核验。',
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
