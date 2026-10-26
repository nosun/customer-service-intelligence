<?php

use App\Models\KnowledgeArticle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $article = KnowledgeArticle::firstOrCreate(['key' => 'ticket_order_action_review_process'], [
                'title' => 'Ticket 取消、标准改码与退款核查的回复边界',
                'knowledge_type' => 'process', 'status' => 'verified', 'version' => 1,
                'automation_tier' => 'draft_sensitive', 'reviewed_at' => now(),
                'applicability' => '当前 Ticket 的只读核查和回复草稿，不构成取消或退款资格政策。',
                'required_facts' => ['当前订单及工单快照', '身份与站点已匹配'],
                'policy_rules' => [
                    '用户已确认一期范围：取消与标准改码纳入流程，退款仅核查与建议，邮件仍在 HelpDesk 发送。',
                    '收到取消或修改请求不代表操作已完成；仅可根据当前 API 事实描述订单状态和记录尺码。',
                    '取消资格、退款比例、费用和到账时间须另有适用站点的已核准政策与事实，不得根据本流程推断。',
                    '20 与 20W 是不同尺码；目标不明确时先澄清，不自动替换。',
                    '订单取消、退款记录、支付渠道结算、客户到账和分期计划调整是不同状态。',
                    '退款记录可描述为内部记录，不能证明客户收到退款；不承诺退款执行、到账或分期取消。',
                    '当前 Ticket 回复指南仅支持本案调查，不覆盖 API 事实或已核准政策，不授权执行。',
                ],
                'prohibited_claims' => ['虚构已取消、已改码、已安排退款、已退款或到账', '未核准的退款比例和时效承诺'],
                'escalation_rules' => ['身份、站点、订单关联、实际制作状态或目标尺码不明确时转人工核查'],
                'source_notes' => '2026-10-22 用户确认的工作台范围与 docs/architecture/ticket-order-actions-workflow.md；仅为应用处理流程，非财务资格政策。',
            ]);
            foreach (['production_cancellation', 'standard_size_change', 'refund_review'] as $intent) {
                $article->bindings()->firstOrCreate(['consumer' => 'ticket', 'intent_key' => $intent, 'usage' => 'draft'], ['priority' => 10, 'enabled' => true]);
            }
            if ($article->wasRecentlyCreated) {
                $article->versions()->create([
                    'version' => 1, 'status' => 'published', 'snapshot' => $article->only([
                        'title', 'knowledge_type', 'site_key', 'applicability', 'automation_tier', 'required_facts',
                        'policy_rules', 'decision_tree', 'reply_strategy', 'reply_templates', 'action_steps', 'prohibited_claims', 'escalation_rules', 'source_notes',
                    ]), 'change_summary' => '用户确认的 Ticket 核查与草稿边界；不授权业务写入或确定退款资格。',
                    'reviewed_at' => now(), 'published_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Retain published process history and any subsequent human policy edits.
    }
};
