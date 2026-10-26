<?php

use App\Models\KnowledgeArticle;
use App\Services\Knowledge\KnowledgeResolver;
use App\Services\ProductQuestions\KnowledgeArticleVersionPublisher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $process = KnowledgeArticle::firstOrCreate(['key' => 'qa_process_confirmed_order_requirements'], [
                'title' => '已确认个案要求的下单记录',
                'knowledge_type' => 'process', 'status' => 'verified', 'site_key' => null,
                'automation_tier' => 'draft_sensitive', 'version' => 1,
                'applicability' => '仅适用于客户准备下单，且本次定制或随订单履行的特别要求已经得到明确确认的情况；不证明任何商品具备特定工艺或服务能力。',
                'required_facts' => ['客户本次明确提出的要求', '当前个案已确认的范围、条件及限制'],
                'policy_rules' => [
                    '已确认可执行的定制要求，必须提醒客户在下单时写入订单 comment（Order Notes），以便随订单记录；不能只停留在产品问答中。',
                    '备注应记录本次具体变更及客户明确要求保持不变的内容；根据实际修改选择必要信息，不把某一种修改的输入要求套用于其他修改。',
                    '仅当本次要求涉及数值时，才要求记录准确数值及计量单位；不得猜测数值、单位或测量方法。',
                    '客户要求且已明确确认的随订单服务也应写入同一备注；只记录证据确认的服务范围及条件，不扩展为其他服务承诺。',
                    '照片只按本次证据确认的可用性、时点及条件回答；不得自行添加拍照时点、质检条件、发送方式、自动发送或已经附图的承诺。',
                    '通过订单备注即可完成的要求记录，不需要客户另行联系客服或在公开问答补充个人数据；只有仍需个别协商或核实的独立问题才进入相应咨询流程。',
                    '本流程不确认工艺可行性、费用、交期、照片服务或退换资格；这些结论分别由当前事实、明确的个案指导及适用的已验证政策提供。',
                ],
                'decision_tree' => [], 'reply_strategy' => [], 'reply_templates' => [],
                'action_steps' => ['确认本次已获支持的要求及条件', '选择此次要求实际需要的订单备注内容'],
                'prohibited_claims' => ['不得将本流程作为任何工艺或服务已经获批的证据'],
                'escalation_rules' => ['仍有独立要求未经确认或与已验证政策冲突时，保留该问题的核实要求'],
                'source_notes' => '根据用户于 2026-10-21 明确的业务要求：定制要求必须写入订单 comment，能够准确回答时直接解答。工艺和照片等个案结论不沉淀为全局能力。',
                'reviewed_at' => now(),
            ]);
            app(KnowledgeResolver::class)->bind($process, 'product_question');
            $this->snapshot($process, '按用户明确的业务要求沉淀订单记录流程；不包含个案工艺或照片时点。');

            $shipping = KnowledgeArticle::where('key', 'qa_global_ask_shipping_options')->first();
            if ($shipping === null) {
                return;
            }
            $this->snapshot($shipping, '结账计价规则调整前的已发布版本。');
            $rules = $shipping->policy_rules;
            $rules[] = '客户询问含配送的价格时，有当前参考运费可给出预估费用；只有商品价和运费币种一致且金额已核实时才可计算参考总价，最终运费及订单总额以下单结账页为准。';
            $rules[] = '接口已确认 supported=true 且存在配送方式，但未提供 estimated_cost 时，直接说明运费及订单总额在结账页按实际订单计算；仅缺少参考费用不需要转人工或说待确认。此规则不替代配送支持核实，接口失败或支持状态缺失时不得声称可配送。';
            $tree = $shipping->decision_tree;
            array_unshift($tree, [
                'condition' => '接口确认支持目的地且存在配送方式，仅未返回参考费用',
                'outcome' => '说明运费及订单总额在结账页计算并以下单为准，不虚构费用，也不因缺少参考价要求联系客服。',
            ]);
            foreach ($tree as &$branch) {
                $branch['condition'] = str_replace('查询失败、数据缺失', '查询失败、配送支持或方式数据缺失', $branch['condition']);
            }
            unset($branch);
            $shipping->update([
                'policy_rules' => $rules,
                'decision_tree' => $tree,
                'required_facts' => array_map(fn ($fact) => str_replace('methods[].estimated_cost', 'methods[].estimated_cost（可为空，缺少参考价时走结账计价）', $fact), $shipping->required_facts),
                'escalation_rules' => array_map(fn ($rule) => str_replace('数据缺失', '配送支持或方式数据缺失', $rule), $shipping->escalation_rules),
                'source_notes' => $shipping->source_notes."\n2026-10-21 用户确认：参考运费可查询 API，最终以下单结账为准；仅缺参考价不要求联系客服。",
                'version' => $shipping->version + 1,
                'reviewed_at' => now(),
            ]);
            $this->snapshot($shipping, '按用户明确的业务要求补充参考运费与结账计价分支，保留配送支持与接口失败保护。');
        });
    }

    public function down(): void
    {
        // Published business rules retain their version history; use a new reviewed version to revert.
    }

    private function snapshot(KnowledgeArticle $article, string $summary): void
    {
        $article->versions()->firstOrCreate(['version' => $article->version], [
            'status' => 'published',
            'snapshot' => app(KnowledgeArticleVersionPublisher::class)->snapshot($article),
            'change_summary' => $summary,
            'reviewed_at' => $article->reviewed_at,
            'published_at' => now(),
        ]);
    }
};
