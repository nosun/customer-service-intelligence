<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductQuestionAnalysis extends Model
{
    public const INTENTS = [
        'ask_product_details', 'ask_product_photos', 'compare_products', 'ask_weight', 'ask_pockets', 'ask_lining',
        'ask_built_in_bra', 'ask_closure', 'ask_train', 'ask_available_colors', 'ask_color_match',
        'ask_color_photos', 'ask_fabric', 'ask_swatch', 'ask_size_recommendation', 'ask_size_chart',
        'ask_fit', 'ask_plus_size', 'request_custom_size', 'request_custom_color', 'request_custom_length',
        'request_add_pockets', 'request_add_sleeves', 'request_remove_sleeves', 'request_change_neckline',
        'request_change_back', 'request_remove_train', 'request_add_train', 'request_mix_designs',
        'request_other_customization', 'ask_in_stock', 'ask_restock', 'ask_production_time',
        'request_rush_order', 'ask_delivery_time', 'ask_shipping_country', 'ask_shipping_cost',
        'ask_tracking', 'ask_price', 'ask_discount', 'ask_payment', 'ask_guarantee', 'ask_return_policy',
        'ask_exchange_policy', 'ask_cancellation_policy', 'ask_refund', 'ask_order_status',
        'ask_how_to_order', 'request_contact', 'spam_or_irrelevant', 'unclear_or_multi_intent',
    ];

    public const FACTORY_CAPABILITIES = [
        'custom_size', 'custom_color', 'custom_length', 'add_pockets', 'add_sleeves', 'remove_sleeves',
        'change_neckline', 'change_back', 'remove_train', 'add_train', 'mix_design_elements',
        'change_fabric', 'change_lining', 'change_embellishment', 'rush_production', 'other_customization',
    ];

    public const CATEGORIES = [
        'product_details' => '产品信息与细节',
        'color_material' => '颜色与面料',
        'size_fit' => '尺码与测量',
        'customization' => '定制与修改',
        'availability' => '库存与补货',
        'production_time' => '制作周期',
        'shipping_delivery' => '配送时间、费用与地区',
        'pricing_promotion' => '价格与优惠',
        'return_cancel_after_sales' => '退换、取消与售后',
        'order_contact' => '订单与联系问题',
        'other_spam' => '其他或垃圾信息',
    ];

    public const SCOPES = [
        'product_specific' => '产品专属',
        'site_policy' => '站点通用政策',
        'mixed' => '产品与政策混合',
        'unknown' => '无法判断',
    ];

    public const ANSWERABILITY = [
        'direct' => '可直接回答',
        'needs_business_facts' => '需要业务事实',
        'needs_clarification' => '需要追问',
        'escalate' => '需要人工升级',
    ];

    public const ANSWER_SOURCES = [
        'product_catalog' => '商品数据',
        'verified_policy' => '已确认政策',
        'product_and_policy' => '商品数据与政策',
        'factory_capability' => '工厂能力确认',
        'customer_input' => '客户补充信息',
        'human_judgment' => '人工判断',
        'unknown' => '暂时无法判断',
    ];

    public const WORK_QUEUES = [
        'catalog_answerable' => '商品数据可回答',
        'catalog_data_missing' => '商品资料待补充',
        'policy_review' => '政策待确认',
        'factory_review' => '工厂待确认',
        'customer_clarification' => '需要追问客户',
        'human_review' => '需要人工处理',
    ];

    public const RISKS = [
        'low' => '低风险',
        'medium' => '中风险',
        'high' => '高风险',
    ];

    public const REPLY_PATTERNS = [
        'direct_answer' => '直接回答',
        'conditional_answer' => '条件式回答',
        'clarification_request' => '请求补充信息',
        'recommendation' => '推荐或替代方案',
        'refusal_boundary' => '说明限制或拒绝',
        'escalation' => '升级人工处理',
        'incomplete' => '回答不完整',
        'unanswered' => '尚未回答',
    ];

    public const RESPONSE_MOVES = [
        'acknowledge' => '确认理解',
        'direct_conclusion' => '直接结论',
        'provide_facts' => '提供事实',
        'state_conditions' => '说明条件',
        'set_expectation' => '设置预期',
        'request_information' => '请求信息',
        'give_steps' => '给出步骤',
        'recommend_alternative' => '推荐替代方案',
        'state_limit' => '说明限制',
        'escalate' => '升级人工',
    ];

    public const AUTOMATION = [
        'auto_candidate' => '低风险自动化候选',
        'human_review' => '生成后人工审核',
        'draft_only' => '仅生成草稿',
        'never_auto' => '禁止自动回复',
    ];

    public const REVIEW_STATUSES = [
        'candidate' => '待审核',
        'confirmed' => '已确认',
        'corrected' => '已修正',
        'rejected' => '已驳回',
    ];

    protected $guarded = [];

    public function question(): BelongsTo
    {
        return $this->belongsTo(ProductQuestion::class, 'product_question_id');
    }

    protected function casts(): array
    {
        return [
            'secondary_categories' => 'array',
            'required_facts' => 'array',
            'missing_facts' => 'array',
            'response_moves' => 'array',
            'answer_assessment' => 'array',
            'confidence' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }
}
