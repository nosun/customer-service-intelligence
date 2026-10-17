<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_question_policy_candidates', 'automation_tier')) {
            Schema::table('product_question_policy_candidates', function (Blueprint $table): void {
                $table->string('automation_tier')->default('draft_sensitive')->after('confidence')->index();
            });
        }
        if (! Schema::hasColumn('knowledge_articles', 'automation_tier')) {
            Schema::table('knowledge_articles', function (Blueprint $table): void {
                $table->string('automation_tier')->default('draft_sensitive')->after('status')->index();
            });
        }
        if (! Schema::hasColumn('commerce_sites', 'operates_online_only')) {
            Schema::table('commerce_sites', function (Blueprint $table): void {
                $table->boolean('operates_online_only')->nullable()->after('customer_service_email');
                $table->boolean('live_chat_enabled')->nullable()->after('operates_online_only');
                $table->string('public_phone')->nullable()->after('live_chat_enabled');
                $table->string('try_on_policy')->nullable()->after('public_phone');
                $table->text('physical_store_info')->nullable()->after('try_on_policy');
            });
            DB::table('commerce_sites')->where('site_key', 'dorriswedding')->update([
                'operates_online_only' => true,
                'live_chat_enabled' => true,
                'try_on_policy' => 'not_offered',
            ]);
        }

        DB::transaction(function (): void {
            $this->replacePolicy(18, $this->howToOrder(), 'draft_low_risk', 0.9000,
                '复核完成：仅保留注册、登录、下单、商品链接、订单查询入口和网站故障排查。门店、试穿、电话、支付方式及时效不再由本策略推断。');
            $this->replacePolicy(27, $this->payment(), 'draft_sensitive', 0.8500,
                '复核完成：货币与结算说明转 Policy 21；本策略仅处理结账页当前支付方式、卡支付安全和通用失败排查，不再承诺 PayPal 或游客结账。');
            $this->replacePolicy(28, $this->guarantee(), 'draft_sensitive', 0.8000,
                '复核完成：只允许说明非绝对一致、引用当前商品事实和保证页面；不推断具体材质等级、瑕疵责任、退款或重做结果。');
            $this->replacePolicy(22, $this->contact(), 'draft_low_risk', 0.9500,
                '复核完成：改为跨站点条件式联系策略。邮箱和公开地址读取站点配置；Live Chat、电话、门店和试穿只在对应站点事实已配置时说明。');

            DB::table('product_question_policy_candidates')->whereIn('id', [17, 19])->update([
                'conflicts' => '[]',
                'updated_at' => now(),
            ]);
            DB::table('product_question_policy_candidates')->where('id', 23)->update([
                'open_questions' => '[]',
                'updated_at' => now(),
            ]);

            foreach ([22, 25, 26] as $id) {
                $this->makeGlobal($id);
            }

            $lowRisk = [16, 18, 20, 21, 22, 23, 30];
            DB::table('product_question_policy_candidates')->where('review_status', 'confirmed')->update([
                'automation_tier' => 'draft_sensitive',
                'updated_at' => now(),
            ]);
            DB::table('product_question_policy_candidates')->whereIn('id', $lowRisk)->update([
                'automation_tier' => 'draft_low_risk',
                'updated_at' => now(),
            ]);
            DB::table('knowledge_articles')->where('key', 'like', 'qa_%')->update([
                'automation_tier' => 'draft_sensitive',
                'updated_at' => now(),
            ]);
            DB::table('knowledge_articles')->whereIn('id', DB::table('product_question_policy_candidates')
                ->whereIn('id', $lowRisk)->whereNotNull('knowledge_article_id')->pluck('knowledge_article_id'))
                ->update(['automation_tier' => 'draft_low_risk', 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        // Reviewed policy decisions are not reconstructed automatically.
    }

    /** @param array<string, mixed> $content */
    private function replacePolicy(int $id, array $content, string $tier, float $confidence, string $notes): void
    {
        $candidate = DB::table('product_question_policy_candidates')->where('id', $id)->first();
        if ($candidate === null) {
            return;
        }

        $now = now();
        DB::table('product_question_policy_candidates')->where('id', $id)->update([
            ...$content,
            'conflicts' => '[]',
            'open_questions' => '[]',
            'confidence' => $confidence,
            'automation_tier' => $tier,
            'review_notes' => $notes,
            'updated_at' => $now,
        ]);

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
            'automation_tier' => $tier,
            'source_notes' => $notes,
            'version' => ((int) $article->version) + 1,
            'updated_at' => $now,
        ]);
    }

    private function makeGlobal(int $id): void
    {
        $candidate = DB::table('product_question_policy_candidates')->where('id', $id)->first();
        if ($candidate === null || $candidate->knowledge_article_id === null) {
            return;
        }

        $article = DB::table('knowledge_articles')->where('id', $candidate->knowledge_article_id)->first();
        if ($article === null) {
            return;
        }

        $key = 'qa_global_'.$candidate->scenario_key;
        DB::table('product_question_policy_candidates')->where('id', $id)->update([
            'scope' => 'global',
            'updated_at' => now(),
        ]);
        DB::table('knowledge_articles')->where('id', $article->id)->update([
            'key' => $key,
            'site_key' => null,
            'source_notes' => (string) $article->source_notes.' 已改为跨站点通用流程；具体品牌、URL、邮箱及业务事实必须从当前站点读取。',
            'version' => ((int) $article->version) + 1,
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function howToOrder(): array
    {
        return [
            'title' => '网站注册、登录、下单与查询入口指引',
            'definition' => '客户询问如何注册、登录、下单、打开商品页、查找订单查询入口或排查基础页面故障。',
            'applicability' => '适用于采用统一商城路径的站点。门店、试穿和联系方式使用 request_contact；实际订单进度使用 ask_order_status；支付方式和支付失败使用 ask_payment。',
            'required_facts' => $this->json(['当前站点公开地址、品牌名称和客服邮箱', '客户要完成的具体网站任务', '涉及商品时的当前 product_url', '页面报错时的脱敏错误信息']),
            'policy_rules' => $this->json([
                '注册和登录使用 {{ site.public_base_url }}/register。',
                '下单按选择商品选项、加入购物车、结账、填写配送信息、选择当前可用配送与支付方式、核对并提交的稳定流程说明。',
                '下单说明页使用 {{ site.public_base_url }}/article/order-procedure.html，但不得引用其中未经实时核验的固定时效。',
                '仅寻找订单查询入口时使用 {{ site.public_base_url }}/order/status；实际订单状态转 ask_order_status。',
                '商品链接来自当前商品快照；缺失或下架时不得猜测。',
                '不得要求密码、验证码、完整卡号、安全码或未脱敏支付截图。',
            ]),
            'decision_tree' => $this->json([
                ['condition' => '客户询问注册或登录入口', 'outcome' => '提供当前站点 /register 链接和必要的安全提示。'],
                ['condition' => '客户询问如何下单', 'outcome' => '提供稳定的任务步骤及下单说明页，不引用固定按钮文字或时效。'],
                ['condition' => '客户只询问在哪里查看订单', 'outcome' => '提供 /order/status；若询问具体状态则转 ask_order_status。'],
                ['condition' => '客户打不开商品页', 'outcome' => '使用当前 product_url；缺失、下架或异常时转人工。'],
                ['condition' => '客户遇到页面错误', 'outcome' => '给出刷新、隐私窗口或更换浏览器等基础排查，并收集脱敏诊断信息。'],
            ]),
            'reply_strategy' => $this->json([
                ['stage' => '识别任务', 'goal' => '只回答当前网站操作问题', 'content_requirements' => ['注册、下单、查订单入口、商品链接或页面故障']],
                ['stage' => '提供稳定入口', 'goal' => '避免依赖易变化的按钮文案', 'content_requirements' => ['当前站点 URL', '任务目标步骤']],
                ['stage' => '安全检查', 'goal' => '避免收集账户和支付秘密', 'content_requirements' => ['只收集脱敏错误信息']],
            ]),
            'reply_templates' => $this->json([
                ['conditions' => ['客户询问注册或登录'], 'body' => 'You can sign in or create an account at {{ site.public_base_url }}/register. Please do not send us your password or verification code.'],
                ['conditions' => ['客户询问如何下单'], 'body' => 'Open the product page, select all required options, add the item to your cart, and continue to checkout. Enter your shipping details, choose from the currently available shipping and payment methods, review the order, and submit it. You can also view {{ site.public_base_url }}/article/order-procedure.html.'],
                ['conditions' => ['客户只寻找订单查询入口'], 'body' => 'You can check your order at {{ site.public_base_url }}/order/status using your order number and the email address used at checkout.'],
                ['conditions' => ['客户遇到网站错误'], 'body' => 'Please refresh the page and try again in a private browsing window or another browser. If the issue continues, send us the page URL, the error message, and a screenshot with all passwords, verification codes, card details, and other sensitive information hidden.'],
            ]),
            'action_steps' => $this->json(['读取当前站点变量', '识别客户网站任务', '需要商品链接时读取当前商品快照', '生成英文草稿并检查链接和敏感信息', '实际订单、支付、门店或试穿问题转对应策略']),
            'prohibited_claims' => $this->json(['不得写死品牌、域名或客服邮箱', '不得引用未经验证的固定制作、配送或追踪时效', '不得猜测商品链接或恢复时间', '不得索取密码、验证码或完整支付信息', '不得在本策略中断言门店、试穿、电话或具体支付方式']),
            'escalation_rules' => $this->json(['商品 URL 缺失、下架或异常', '基础排查后网站错误仍存在', '客户提供账户安全、支付或欺诈相关信息', '站点路径无法访问或与策略不一致']),
        ];
    }

    /** @return array<string, mixed> */
    private function payment(): array
    {
        return [
            'title' => '支付方式与卡支付受阻处理',
            'definition' => '客户询问如何选择当前可用支付方式，或信用卡、借记卡支付失败时的安全排查。',
            'applicability' => '适用于结账付款和支付失败。商品价格、显示币种、语言与 USD 结算说明使用 ask_price；退款、拒付和已扣款争议转人工售后。',
            'required_facts' => $this->json(['当前结账页实际显示的支付方式', '客户看到的脱敏错误信息', '当前站点客服邮箱']),
            'policy_rules' => $this->json([
                '只可建议客户选择当前结账页实际显示的支付方式，不维护或猜测固定支付方式清单。',
                '卡支付失败时可建议核对余额、账单地址、在线及国际交易权限，并尝试结账页显示的其他方式、隐私窗口或其他浏览器设备。',
                '不得断言失败原因属于银行、风控、3DS 或网站系统。',
                '不得要求客户提供完整卡号、有效期、安全码、密码或验证码；截图必须隐藏支付和个人信息。',
                '货币显示、支持币种与 USD 结算问题转 ask_price。',
            ]),
            'decision_tree' => $this->json([
                ['condition' => '客户询问支持什么支付方式', 'outcome' => '请客户以当前结账页显示的选项为准；无实时结账能力数据时不列固定清单。'],
                ['condition' => '首次卡支付失败且没有已扣款争议', 'outcome' => '提供安全的通用排查步骤，不归因。'],
                ['condition' => '多次失败、出现已扣款、重复扣款、拒付或欺诈疑虑', 'outcome' => '停止通用排查并转人工支付支持。'],
                ['condition' => '客户询问币种、汇率或 USD 结算', 'outcome' => '转 ask_price。'],
            ]),
            'reply_strategy' => $this->json([
                ['stage' => '识别支付场景', 'goal' => '区分方式选择、支付失败和已扣款争议', 'content_requirements' => ['客户看到的脱敏现象']],
                ['stage' => '提供安全排查', 'goal' => '给出不归因的低风险步骤', 'content_requirements' => ['账单地址、交易权限、当前其他方式、浏览器设备']],
                ['stage' => '保护支付信息', 'goal' => '避免收集支付秘密', 'content_requirements' => ['脱敏截图', '禁止卡号和安全码']],
            ]),
            'reply_templates' => $this->json([
                ['conditions' => ['客户询问支付方式'], 'body' => 'Please continue to checkout and choose from the payment methods currently displayed there. Available methods can vary, so the checkout page is the current source of truth. Never send card details, passwords, or verification codes to customer service.'],
                ['conditions' => ['卡支付首次失败且无扣款争议'], 'body' => 'We are sorry the payment did not go through. Please check the available balance, confirm that the billing address matches your card issuer records, and make sure online and international transactions are allowed. You may also try another payment method shown at checkout, a private browsing window, or another browser or device. If the issue continues, send {{ site.customer_service_email }} a screenshot of the error with all card and personal details hidden.'],
            ]),
            'action_steps' => $this->json(['区分支付方式、失败和扣款争议', '不具备实时能力时只引用结账页', '提供低风险排查', '检查回复未索取支付秘密', '多次失败或扣款争议转人工']),
            'prohibited_claims' => $this->json(['不得承诺特定支付方式始终可用', '不得承诺 PayPal 或游客结账', '不得断言失败原因', '不得索取完整卡号、有效期、安全码、密码或验证码', '不得承诺退款、折扣、赔偿或加急处理']),
            'escalation_rules' => $this->json(['多次支付失败', '已扣款、重复扣款、拒付或汇率争议', '欺诈、账户安全或支付信息泄露风险', '客户无法安全提供脱敏错误信息']),
        ];
    }

    /** @return array<string, mixed> */
    private function guarantee(): array
    {
        return [
            'title' => '售前品质与商品图片一致性说明',
            'definition' => '客户在购买前询问品质、做工或成衣能否与商品图片完全一致。',
            'applicability' => '仅适用于售前一般性品质疑虑。已收到商品后的具体质量、错发、商品不符、退款或补偿诉求使用 ask_refund。',
            'required_facts' => $this->json(['当前商品名称、图片和已同步属性', '当前站点保证页面链接', '客户具体担忧']),
            'policy_rules' => $this->json([
                '不得承诺成衣在所有光线、屏幕和个体条件下与图片 100% 一致。',
                '只引用当前商品快照已提供的款式、颜色、面料和结构事实；缺失时不补充。',
                '可说明颜色和视觉质感可能受光线及屏幕影响，并提供 {{ site.public_base_url }}/article/our-guarantee.html。',
                '客户已收到商品并提出具体瑕疵时转 ask_refund，不在售前策略中判断责任或结果。',
            ]),
            'decision_tree' => $this->json([
                ['condition' => '客户只询问能否与图片完全一致', 'outcome' => '说明按商品展示款式制作，但无法作绝对一致承诺，并附保证页面。'],
                ['condition' => '客户询问某个具体面料、颜色或结构细节', 'outcome' => '只使用当前商品快照中的对应事实；缺失时说明需要核实。'],
                ['condition' => '客户已收到商品并报告瑕疵或不符', 'outcome' => '转 ask_refund 和人工售后。'],
            ]),
            'reply_strategy' => $this->json([
                ['stage' => '承认顾虑', 'goal' => '回应重要购买的担忧', 'content_requirements' => ['不回避但不承认未核实问题']],
                ['stage' => '提供可核实事实', 'goal' => '限定在当前商品和保证页面', 'content_requirements' => ['商品快照事实', '非绝对一致边界']],
                ['stage' => '说明支持路径', 'goal' => '引导至正式保证信息', 'content_requirements' => ['保证页面', '已收货问题转售后']],
            ]),
            'reply_templates' => $this->json([
                ['conditions' => ['客户售前询问品质或与图片一致性'], 'body' => 'We understand your concern, especially for an important purchase. {{ product.name }} is made to the style shown in the current product photos, but color and finish can appear slightly different under different lighting or screen settings, so we cannot promise a 100% identical appearance in every situation. You can review our current guarantee at {{ site.public_base_url }}/article/our-guarantee.html.'],
            ]),
            'action_steps' => $this->json(['确认仍属于售前问题', '读取当前商品快照', '只使用已存在的商品事实', '加入非绝对一致边界和保证页面', '具体瑕疵或结果承诺转人工售后']),
            'prohibited_claims' => $this->json(['不得承诺 100% 一致、完全相同或无任何瑕疵', '不得编造具体材料等级、工艺、认证或质量统计', '不得承诺退款、重做或赔偿结果', '不得否定或承认未经核实的具体负面评价']),
            'escalation_rules' => $this->json(['当前商品事实或保证页面不可用', '客户要求具名书面保证', '客户提供具体瑕疵证据或已收到商品', '涉及法律、欺诈、健康或安全指控']),
        ];
    }

    /** @return array<string, mixed> */
    private function contact(): array
    {
        return [
            'title' => '门店、试穿与官方联系渠道',
            'definition' => '客户询问线下门店、试穿、客服电话、在线客服、客服邮箱或能否直接联系制作团队。',
            'applicability' => '适用于所有已配置官方联系渠道和门店/试穿事实的站点。生产国家使用 ask_product_origin；网站操作使用 ask_how_to_order。',
            'required_facts' => $this->json(['site.public_base_url', 'site.customer_service_email', 'site.live_chat_enabled', 'site.public_phone', 'site.operates_online_only', 'site.try_on_policy', 'site.physical_store_info']),
            'policy_rules' => $this->json([
                '客服邮箱和联系页面必须读取当前站点配置。',
                '只有 site.live_chat_enabled=true 时才可说明提供 Live Chat；只有配置 public_phone 时才可提供客服电话。',
                '只有 site.operates_online_only=true 时才可说明仅在线经营；门店和试穿结论必须来自当前站点的 physical_store_info 与 try_on_policy。',
                '客户不能直接联系裁缝或制作团队；修改需求由客服收集后内部评估。',
                '站点事实缺失时，只提供已确认的邮箱和 Contact Us 页面，并说明门店或试穿信息需要核实。',
            ]),
            'decision_tree' => $this->json([
                ['condition' => '客户询问一般联系渠道', 'outcome' => '列出当前站点已配置的邮箱、联系页、Live Chat 和电话；不展示空值。'],
                ['condition' => '客户询问门店或试穿且站点事实完整', 'outcome' => '按 operates_online_only、physical_store_info 和 try_on_policy 回答。'],
                ['condition' => '门店或试穿事实缺失', 'outcome' => '不作否定或肯定结论，提供客服邮箱并转人工核实。'],
                ['condition' => '客户要求直接联系裁缝或工厂', 'outcome' => '引导客户把具体需求提交给客服，由内部团队评估。'],
            ]),
            'reply_strategy' => $this->json([
                ['stage' => '识别渠道', 'goal' => '区分一般联系、门店、试穿和制作团队', 'content_requirements' => ['客户实际询问的渠道']],
                ['stage' => '读取站点事实', 'goal' => '只使用已配置的当前站点能力', 'content_requirements' => ['邮箱、页面、Live Chat、电话、门店、试穿']],
                ['stage' => '处理缺失', 'goal' => '避免把一个站点结论复制到另一站点', 'content_requirements' => ['缺失时转人工核实']],
            ]),
            'reply_templates' => $this->json([
                ['conditions' => ['客户询问一般官方联系渠道'], 'body' => 'You can contact our customer-service team at {{ site.customer_service_email }} or review the current contact options at {{ site.public_base_url }}/article/contact-us.html.'],
                ['conditions' => ['当前站点已确认仅在线经营且不提供试穿'], 'body' => '{{ site.brand_name }} currently operates online and does not offer a physical store or in-person try-on service. You can review the product photos and current Size Chart at {{ site.public_base_url }}, or contact {{ site.customer_service_email }} for help.'],
                ['conditions' => ['客户要求直接联系制作团队'], 'body' => 'We do not provide direct public contact details for our tailoring or production team. Please email your exact requested changes to {{ site.customer_service_email }} so the team can confirm feasibility, cost, and lead-time impact.'],
            ]),
            'action_steps' => $this->json(['读取当前站点全部联系与门店事实', '只选择已配置的渠道', '门店或试穿事实缺失时转人工', '制作需求转客服内部评估', '生成英文草稿后检查没有其他站点品牌或渠道']),
            'prohibited_claims' => $this->json(['不得写死站点品牌、域名或邮箱', '不得把某一站点的门店或电话结论套用于其他站点', '不得提供未配置电话、门店、展厅、经销商或员工联系方式', '不得在缺少站点事实时断言提供或不提供试穿', '不得承诺修改可行性、费用或工期']),
            'escalation_rules' => $this->json(['客服邮箱或联系页面未配置', '门店、试穿、电话或 Live Chat 事实缺失', '客户要求例外试穿、合作门店或经销商', '涉及欺诈、支付安全、法律或合规指控']),
        ];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
};
