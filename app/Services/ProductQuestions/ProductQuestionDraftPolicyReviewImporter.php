<?php

namespace App\Services\ProductQuestions;

use App\Models\CommerceSite;
use App\Models\ProductQuestionDraft;
use App\Models\ProductQuestionPolicyCandidate;
use App\Models\ProductQuestionPolicyRun;
use Illuminate\Support\Carbon;
use RuntimeException;

class ProductQuestionDraftPolicyReviewImporter
{
    /** @var array<string,array<string,mixed>> */
    private const POLICIES = [
        'ask_swatch' => [
            'scope' => 'global',
            'category' => 'color_material',
            'title' => '面料样卡订购规则',
            'definition' => '客户在购买商品前询问是否可以获取面料或颜色样卡。',
            'applicability' => '所有站点的售前面料或颜色样卡咨询；不适用于直接确认商品材质，也不表示样卡与不同材质成品的颜色效果完全一致。',
            'required_facts' => ['当前商品的面料构成', '样卡页面当前可选颜色'],
            'policy_rules' => [
                '所有站点均提供 satin、chiffon 和 matte satin 三种面料样卡，其他面料或装饰没有对应样卡时不得声称可以提供。',
                '样卡统一通过 {{ site.public_base_url }}/order-swatch.html 页面订购。',
                '每份样卡价格为 US$0.01，另收配送费，不限制配送地区。',
                '若商品包含样卡不支持的面料或装饰，只能将可购买的样卡作为颜色参考，并明确说明不同材质上的最终颜色可能存在差异。',
                '回复保持简洁，不复述商品标题或 SN。',
            ],
            'decision_tree' => [
                ['condition' => '客户询问 satin、chiffon 或 matte satin 样卡', 'outcome' => '确认可以从样卡页面订购，说明每份 US$0.01 且另收配送费'],
                ['condition' => '商品同时包含受支持和不受支持的面料或装饰', 'outcome' => '仅推荐受支持的样卡作为颜色参考，并提示不同材质的最终颜色可能有差异'],
                ['condition' => '客户要求其他面料样卡', 'outcome' => '明确说明当前只提供 satin、chiffon 和 matte satin，不虚构页面外选项'],
                ['condition' => '样卡页面没有客户需要的颜色', 'outcome' => '说明当前页面没有该选项，不承诺团队稍后提供或联系客户'],
            ],
            'reply_strategy' => [[
                'stage' => 'answer',
                'goal' => '直接回答样卡是否可用并提供订购入口',
                'content_requirements' => ['说明支持的三种面料', '说明 US$0.01 价格及另收配送费', '使用当前站点域名生成样卡页面链接', '涉及不同材质时提示色差'],
            ]],
            'reply_templates' => [
                [
                    'conditions' => ['客户询问受支持的样卡'],
                    'body' => 'We offer color swatches in satin, chiffon, and matte satin for US$0.01 each, plus shipping. You can order them here: {{ site.public_base_url }}/order-swatch.html.',
                ],
                [
                    'conditions' => ['商品同时包含受支持和不受支持的面料或装饰'],
                    'body' => 'We do not currently offer a swatch for {{ unsupported_material }}, but you can order a {{ supported_material }} sample for US$0.01, plus shipping, as a color reference here: {{ site.public_base_url }}/order-swatch.html. Please note that the final color may look slightly different on {{ unsupported_material }}.',
                ],
            ],
            'action_steps' => ['读取当前商品面料构成', '确认客户需要的面料是否属于支持的三种样卡', '提供当前站点的样卡页面链接'],
            'prohibited_claims' => ['不得声称样卡免费或免邮', '不得承诺 satin、chiffon 和 matte satin 以外的样卡', '不得保证样卡与不同材质成品完全无色差', '不得承诺团队稍后核实或联系客户'],
            'escalation_rules' => ['商品面料构成无法取得，且无法判断应推荐哪种样卡时进入人工审核'],
            'open_questions' => [],
        ],
        'ask_cancellation_policy' => [
            'category' => 'return_cancel_after_sales',
            'title' => '整单取消与退款规则',
            'definition' => '客户咨询或申请取消整个订单，包括制作前、制作中、已发货、已取消和退款处理中的各种状态。',
            'applicability' => '适用于整单取消咨询和请求；不适用于只取消部分商品、退货、单纯查询退款进度、询问系统为何取消订单或取消订阅。',
            'required_facts' => ['客户是在咨询还是明确要求取消', '订单号和请求人身份', '首次明确取消请求时间', '由工厂逐单确认的实际制作开始时间', '请求时的发货状态', '商品实付、运费、ShipSafe 和加急费拆分', '已有取消和退款记录', '当前官网取消政策版本', '暂停制作或发货的实际执行状态'],
            'policy_rules' => [
                '条件式咨询只解释适用规则，不构成取消授权；明确取消请求仍须通过订单、身份、范围和资格检查。',
                '收到取消请求、订单已取消、退款已发起和退款已完成是四个不同事实，不得混用。',
                '取消判断使用客户首次明确提出取消的时间，并还原该时点的制作和发货状态。',
                '制作开始前取消可获得全额退款；客户已明确主动提出取消且经核验符合全额退款条件时，无需再次向客户确认。',
                '制作开始后仍可申请取消；退款档位从工厂逐单确认的实际制作开始时间计算，不使用订单确认时间或制作通知时间替代。',
                '商品价、运费、ShipSafe 和加急费的退款计算、折扣分摊与舍入均以当前 {{ site.public_base_url }}/article/cancellation-policy.html 为准，不从历史案例复制。',
                '请求时已经发货的订单不能取消，应转入到货后的退货资格判断。',
                '可能产生扣款时，必须先提供准确退款金额和不可退金额构成，并取得客户对当前报价的明确确认。',
                '取消申请通过 {{ site.customer_service_email }} 提交，并包含订单号和取消原因。',
                '收到客户明确取消请求后，客服应立即通知跟单人员暂停制作或发货，并保持暂停，直到形成明确的取消结论；未取得执行记录前不得对客户声称已经暂停。',
                '取消成功但退款记录异常不是常规分支；一旦发生，必须核对支付和退款记录，不重复取消，也不得在记录一致前声称退款完成。',
                '不对外承诺取消处理时限或银行入账时限；退款到账时间取决于发卡行。',
                '当前系统只允许生成只读建议和回复草稿，不得声称已暂停、已取消或已退款，也不得虚构团队或工厂后续动作。',
            ],
            'decision_tree' => [
                ['condition' => '客户只是询问能否取消或能退多少', 'outcome' => '解释一般规则和申请入口，不执行取消，也不承诺具体退款金额'],
                ['condition' => '客户明确要求整单取消，但订单号、身份或范围不完整', 'outcome' => '请求补充必要信息，不执行任何写操作'],
                ['condition' => '订单已经取消且退款已完成', 'outcome' => '仅依据可验证记录说明取消和退款结果，禁止重复执行'],
                ['condition' => '订单已经取消但退款未完成', 'outcome' => '进入退款核对流程，不重复提交取消'],
                ['condition' => '首次请求时订单已经发货', 'outcome' => '说明不能再作为订单取消，转退货资格判断'],
                ['condition' => '客户明确主动要求取消，且工厂确认首次请求时尚未开始制作并符合全额退款条件', 'outcome' => '无需客户二次确认；经内部批准后执行整单取消和全额退款'],
                ['condition' => '首次请求时已经开始制作，且工厂已确认实际制作开始时间', 'outcome' => '人工按当前取消政策计算退款报价，列明可退与不可退金额，等待客户明确确认'],
                ['condition' => '收到明确取消请求且订单尚可暂停', 'outcome' => '客服立即通知跟单人员暂停制作或发货，直至形成明确结论；对外回复前核实暂停记录'],
                ['condition' => '制作状态、时间、费用拆分、政策版本或历史承诺存在缺口或冲突', 'outcome' => '进入人工审核，不猜测退款比例或处理结果'],
            ],
            'reply_strategy' => [
                ['stage' => 'classify', 'goal' => '区分咨询、主动取消、意图不明和取消状态查询', 'content_requirements' => ['只把明确主动请求视为执行授权', '确认是否为整单取消']],
                ['stage' => 'facts', 'goal' => '建立请求时点的订单事实快照', 'content_requirements' => ['核验身份和订单', '读取制作、发货、费用及退款记录', '保留首次明确请求时间']],
                ['stage' => 'answer', 'goal' => '说明当前适用规则和下一步', 'content_requirements' => ['区分政策说明、报价和执行结果', '仅使用已验证事实', '需要客户确认时列明准确金额与组成']],
            ],
            'reply_templates' => [
                ['conditions' => ['一般咨询：制作开始后能否取消'], 'body' => 'You may request cancellation after production has started. The refundable amount is calculated from the order’s actual production start time under our current Cancellation Policy: {{ site.public_base_url }}/article/cancellation-policy.html. Please email {{ site.customer_service_email }} with your order number and cancellation reason. The production start time and exact refund amount must be verified for the order before cancellation.'],
                ['conditions' => ['制作开始前，且政策与订单事实已核实'], 'body' => 'Orders cancelled before tailoring begins are eligible for a full refund. Please email {{ site.customer_service_email }} with your order number and cancellation reason as soon as possible.'],
                ['conditions' => ['请求时已经发货'], 'body' => 'Once an order has shipped, it can no longer be cancelled. Its eligibility for return after delivery must be reviewed under the applicable return policy.'],
                ['conditions' => ['制作中，人工已形成准确部分退款报价'], 'body' => 'Your order has already entered production. The refundable amount would be {{ refund_amount }} {{ currency }}, and the non-refundable amount is {{ non_refundable_amount }} {{ currency }} for {{ non_refundable_reason }}. Please confirm whether you want to cancel the entire order under these terms. The cancellation will not be processed until your confirmation is received.'],
                ['conditions' => ['意图或整单范围不明确'], 'body' => 'Please confirm whether you want to cancel the entire order {{ order_number }}, or whether you are only asking about the available cancellation and refund options. The order will not be cancelled until your request is clear.'],
            ],
            'action_steps' => ['识别请求类型和整单范围', '核验订单、身份和首次请求时间', '由工厂逐单确认实际制作开始时间', '读取请求时的发货状态', '客户明确取消时由客服通知跟单人员暂停制作或发货并记录执行结果', '按当前取消政策核对费用拆分、已退款金额和历史承诺', '全额退款条件满足时无需客户二次确认；存在扣款时提供准确报价并取得客户确认', '人工执行取消与退款后分别回查实际结果'],
            'prohibited_claims' => ['不得用订单确认时间或制作通知时间替代工厂确认的实际制作开始时间', '不得仅凭最终未发货状态推断请求时尚未制作或发货', '不得在缺少实际制作时间或费用拆分时自动套用退款比例', '不得在没有系统记录时声称订单已暂停、已取消、退款已提交或已完成', '不得承诺取消处理或银行入账时限', '不得把历史案例金额或时限作为当前承诺', '不得虚构团队、客服、跟单人员或工厂已经执行后续动作'],
            'escalation_rules' => ['实际制作开始时间必须由工厂逐单确认，缺少确认时转人工', '制作或发货历史无法还原时转人工', '费用拆分、已有退款或历史承诺不完整或冲突时转人工', '取消成功但退款记录异常时进入支付和退款核对，不重复取消'],
            'open_questions' => [],
        ],
    ];

    /** @param list<array<string,mixed>> $predictions */
    public function import(CommerceSite $site, array $predictions, string $workflowVersion): ProductQuestionPolicyRun
    {
        $matched = collect($predictions)
            ->filter(fn (array $prediction): bool => collect((array) ($prediction['intents'] ?? []))
                ->contains(fn (string $intent): bool => isset(self::POLICIES[$intent])))
            ->values();
        if ($matched->isEmpty()) {
            throw new RuntimeException('Predictions do not contain a supported policy review case.');
        }

        $run = ProductQuestionPolicyRun::firstOrNew([
            'batch_key' => "workflow-{$workflowVersion}-policy-review-{$site->site_key}",
        ]);
        if (! $run->exists) {
            $run->fill([
                'commerce_site_id' => $site->id,
                'window_start' => Carbon::now(),
                'window_end' => Carbon::now(),
                'sample_limit' => $matched->count(),
                'selection_seed' => "workflow-{$workflowVersion}",
                'source_assumption' => "Workflow {$workflowVersion} replay cases blocked because verified policy is missing.",
                'status' => 'completed',
                'selected_count' => $matched->count(),
                'analyzed_count' => $matched->count(),
                'failed_count' => 0,
                'provider' => 'deterministic_replay',
                'model' => 'none',
                'prompt_version' => ProductQuestionPolicyPrompt::VERSION,
                'usage' => [],
                'started_at' => Carbon::now(),
                'finished_at' => Carbon::now(),
            ])->save();
        }

        foreach (array_keys(self::POLICIES) as $intent) {
            $prediction = $matched->first(fn (array $item): bool => in_array($intent, (array) ($item['intents'] ?? []), true));
            if (! is_array($prediction)) {
                continue;
            }
            $draft = ProductQuestionDraft::with('question')->find($prediction['draft_id'] ?? null);
            if ($draft === null || $draft->question?->commerce_site_id !== $site->id) {
                throw new RuntimeException("A local Draft for {$intent} was not found for site {$site->site_key}.");
            }

            $candidate = ProductQuestionPolicyCandidate::firstOrNew([
                'product_question_policy_run_id' => $run->id,
                'scenario_key' => $intent,
            ]);
            if ($candidate->exists && $candidate->review_status !== 'candidate') {
                continue;
            }
            $candidate->fill([
                'commerce_site_id' => $site->id,
                ...self::POLICIES[$intent],
                'evidence_question_ids' => [$draft->product_question_id],
                'conflicts' => [],
                'confidence' => 1,
                'review_status' => 'candidate',
            ])->save();
        }

        $run->update(['candidate_count' => $run->candidates()->count()]);

        return $run->fresh();
    }
}
