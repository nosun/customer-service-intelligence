<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenchmarkAnnotation extends Model
{
    public const INTENTS = [
        'presales_product_information' => '售前：商品信息',
        'presales_sizing' => '售前：尺码建议',
        'presales_checkout_payment' => '售前：下单/支付',
        'production_order_status' => '生产：订单/制作进度',
        'production_order_change' => '生产：修改订单',
        'production_cancellation' => '生产：取消订单',
        'production_rush_request' => '生产：加急',
        'logistics_tracking_delivery' => '物流：跟踪/送达',
        'after_sales_return_refund' => '售后：退货/退款',
        'after_sales_product_issue' => '售后：质量/错件/缺件',
        'after_sales_dispute' => '售后：拒付/争议',
        'other' => '其他明确问题',
        'unknown' => '无法判断',
    ];

    public const REQUIRED_FACTS = [
        'customer_identity' => '客户身份',
        'order' => '订单/支付',
        'product' => '商品/选项',
        'production' => '生产工单',
        'shipment' => '包裹/物流',
        'refund' => '退款记录',
        'conversation_history' => '历史沟通',
        'policy' => '客服政策',
    ];

    public const DECISIONS = [
        'answer' => '直接回答',
        'ask_clarification' => '向客户追问',
        'propose_action' => '提出业务操作',
        'escalate' => '转人工/升级',
        'no_action' => '无需处理',
    ];

    public const AUTOMATION_LEVELS = [
        'auto_send' => '可自动发送',
        'draft_review' => '生成草稿，人工审核',
        'human_only' => '仅人工处理',
        'noise' => '噪音/无效问题',
    ];

    public const LIFECYCLE_STAGES = [
        'presales' => '售前',
        'checkout' => '下单/支付',
        'ordered' => '已下单、未进入生产',
        'production' => '生产中',
        'shipped' => '已发货',
        'delivered' => '已送达',
        'after_sales' => '售后',
        'dispute' => '争议/拒付',
        'unknown' => '无法判断',
    ];

    public const RESPONSE_STRATEGIES = [
        'answer_policy' => '解释政策/知识',
        'collect_information' => '收集缺失信息',
        'verify_before_action' => '核实后再操作',
        'execute_and_confirm' => '执行并确认结果',
        'offer_alternative' => '提供替代方案',
        'escalate' => '转人工/升级',
        'no_response' => '无需回复',
    ];

    public const REQUIRED_ACTIONS = [
        'verify_customer_identity' => '验证客户身份',
        'lookup_product' => '查询商品',
        'lookup_order' => '查询订单',
        'lookup_production' => '查询生产状态',
        'lookup_shipment' => '查询包裹/物流',
        'lookup_refund' => '查询退款',
        'collect_measurements' => '收集尺寸',
        'update_order' => '更新订单',
        'update_measurements' => '更新订单尺寸',
        'confirm_action_result' => '向客户确认操作结果',
        'request_approval' => '申请人工审批',
        'none' => '无需业务动作',
    ];

    public const OBSERVED_REPLY_ASSESSMENTS = [
        'matches_gold' => '符合金标准',
        'partially_correct' => '部分正确',
        'incorrect' => '处理错误',
        'unsafe' => '存在不安全承诺',
        'not_applicable' => '无可评价回复',
    ];

    protected $guarded = [];

    public function benchmarkCase(): BelongsTo
    {
        return $this->belongsTo(BenchmarkCase::class);
    }

    public function benchmarkCaseStep(): BelongsTo
    {
        return $this->belongsTo(BenchmarkCaseStep::class);
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    protected function casts(): array
    {
        return [
            'intents' => 'array',
            'required_facts' => 'array',
            'missing_information' => 'array',
            'required_actions' => 'array',
            'knowledge_article_ids' => 'array',
            'knowledge_gaps' => 'array',
            'prohibited_claims' => 'array',
            'evidence_event_ids' => 'array',
            'submitted_at' => 'datetime',
        ];
    }
}
