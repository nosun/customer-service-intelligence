<?php

use App\Models\KnowledgeArticle;
use App\Services\TicketWork\N8nTicketDraftService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('ai_workflows')->insertOrIgnore([
                'workflow_key' => N8nTicketDraftService::INVESTIGATION_KEY,
                'workflow_version' => N8nTicketDraftService::INVESTIGATION_VERSION,
                'center' => 'work', 'domain' => 'tickets', 'status' => 'evaluating',
                'input_schema_version' => '2.0.0', 'output_schema_version' => '2.0.0',
                'prompt_version' => 'ticket-investigation-v2', 'allowed_actions' => '[]',
                'allowed_fact_keys' => '[]', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $routes = [
                'consultation' => ['presales_consultation', 'presales', ['理解问题与商品范围', '查证商品知识或个案调查结论', '直接解释或只问必要问题']],
                'cancellation' => ['production_cancellation', 'in_sales', ['核查订单、发货与实际制作状态', '区分人工决定与执行结果', '分别核查取消、暂停制作和退款安排']],
                'size_change' => ['standard_size_change', 'in_sales', ['锁定具体商品', '核对原尺码、目标尺码与 W 系列', '确认可改性', '回读尺码及测量值再确认结果']],
                'fulfilment' => ['production_order_status', 'in_sales', ['核查订单与制作状态', '区分预计与实际进度', '需要工厂确认的事项进入内部待办']],
                'tracking' => ['logistics_tracking_delivery', 'in_sales', ['核查物流与送达事实', '区分记录与承运商承诺', '明确异常与待核查事项']],
                'refund' => ['refund_review', 'after_sales', ['核查历史承诺、凭证审核与退款范围', '核实金额、币种与渠道', '区分商家安排、发起、渠道结算、银行到账及分期调整']],
                'complaint' => ['after_sales_complaint', 'after_sales', ['理解客户遭遇及期望', '核对已有证据和历史承诺', '只补必要资料', '评估解决方案并沟通', '核查实际结果']],
                'factory' => ['factory_clarification', 'undetermined', ['明确商品及客户条件', '读取已发说明和工厂原文', '区分暂定与最终结论', '补充回复避免重复索证']],
            ];
            foreach ($routes as $key => [$intent, $stage, $steps]) {
                $templates = match ($key) {
                    'cancellation' => [
                        $this->template('cancel_decided', $intent, $stage, 'decided', 'We’ll proceed with cancelling your order.'),
                        $this->template('cancel_completed', $intent, $stage, 'completed', 'Your order has been cancelled as requested.'),
                        $this->template('cancel_requested', $intent, $stage, 'requested', 'Thank you for letting us know that you would like to cancel your order.'),
                    ],
                    'size_change' => [
                        $this->template('size_clarification', $intent, $stage, 'clarification', 'To make sure we use the correct size, could you confirm whether you mean size 20 or 20W?', [], ['target_ambiguity' => '20_or_20W']),
                        $this->template('size_completed', $intent, $stage, 'completed', 'We have changed the dress from size {{original_size}} to size {{target_size}}.', ['original_size', 'target_size']),
                    ],
                    'refund' => [
                        $this->template('donation_decided', 'donation_refund', $stage, 'decided', 'We will proceed with the agreed donation refund.'),
                        $this->template('donation_arranged', 'donation_refund', $stage, 'arranged', 'We have arranged the agreed donation refund.'),
                        $this->template('refund_decided', $intent, $stage, 'decided', 'We will proceed with the agreed refund.'),
                        $this->template('refund_arranged', $intent, $stage, 'arranged', 'We have arranged the agreed refund.'),
                    ],
                    'complaint' => [$this->template('complaint_acknowledgement', $intent, $stage, 'information', 'Thank you for explaining what happened. I’m sorry to hear about the difficulty with your dress.')],
                    default => [],
                };
                $article = KnowledgeArticle::firstOrCreate(['key' => 'ticket_flow_'.$key.'_v2'], [
                    'title' => 'Ticket '.$key.'：业务核查与阶段模板',
                    'knowledge_type' => 'process', 'status' => 'verified', 'version' => 1,
                    'automation_tier' => 'draft_sensitive', 'reviewed_at' => now(),
                    'applicability' => 'Ticket 个案调查及人工审核草稿；不构成财务资格政策或执行授权。',
                    'required_facts' => [], 'action_steps' => $steps,
                    'policy_rules' => [
                        '明确人工决定支持未来办理措辞，未执行状态不与未来办理相冲突；未作决定时不得自动批准。',
                        '指南分为调查事实、人工决定、已执行结果和条件；逐项引用原文，不覆盖真实冲突。',
                        '没有政策不等于违反政策；不得由流程文章推导退款比例、费用或时间。',
                        '历史实例中的 70%、3 个工作日、8–12 个工作日不是全局默认值。',
                        '取消、修改、退款、到账及分期调整分别核实；只生成本地草稿，发送仍在 HelpDesk。',
                    ],
                    'reply_strategy' => [['intent' => $intent, 'business_stage' => $stage, 'template_first' => true,
                        'conditional_blocks' => ['Afterpay 只在渠道已核实时使用', '临时银行入账、争议、捐赠凭证结论仅按本案证据使用']]],
                    'reply_templates' => $templates,
                    'prohibited_claims' => ['虚构已执行操作', '未确认的金额、比例、处理时限及到账承诺'],
                    'escalation_rules' => ['明确事实或政策冲突', '身份、商品范围或目标尺码不明'],
                    'source_notes' => '2026-10-22 用户确认的售前/售中/售后、业务流程及模板优先设计；仅为处理和沟通流程。',
                ]);
                // Wildcard lets process knowledge coexist with site-specific eligibility policies.
                $article->bindings()->firstOrCreate(['consumer' => 'ticket', 'intent_key' => '*', 'usage' => 'draft'], ['priority' => 1, 'enabled' => true]);
                if ($article->wasRecentlyCreated) {
                    $article->versions()->create(['version' => 1, 'status' => 'published',
                        'snapshot' => $article->only(['title', 'knowledge_type', 'site_key', 'applicability', 'automation_tier', 'required_facts', 'policy_rules', 'decision_tree', 'reply_strategy', 'reply_templates', 'action_steps', 'prohibited_claims', 'escalation_rules', 'source_notes']),
                        'change_summary' => '按已确认设计建立业务流程与阶段模板；不发布财务资格规则。',
                        'reviewed_at' => now(), 'published_at' => now()]);
                }
            }
        });
    }

    private function template(string $id, string $business, string $stage, string $phase, string $body, array $variables = [], array $conditions = []): array
    {
        return ['id' => $id, 'business' => $business, 'business_stage' => $stage, 'action_stage' => $phase,
            'body' => $body, 'variables' => $variables, 'conditions' => $conditions,
            'preserve' => ['办理阶段、商品范围与关键承诺不能改写'], 'prohibited' => ['无依据退款金额、比例、到账时间']];
    }

    public function down(): void
    {
        // Keep published versions and draft audit records; select v1 to roll back runtime routing.
    }
};
