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
                ->where('id', 22)
                ->where('scenario_key', 'request_contact')
                ->first();
            if ($candidate === null || $candidate->review_status === 'confirmed') {
                return;
            }

            $now = now();
            $reviewerId = DB::table(config('admin.database.users_table'))->min('id');
            $policyRules = [
                '当前站点仅在线经营，不提供线下门店、展厅或线下试穿地点。',
                '官方支持渠道包括网站 Live Chat、{{ site.customer_service_email }} 与 {{ site.public_base_url }}/article/contact-us.html。',
                '当前未公开客服电话号码，不得猜测或提供未公开号码。',
                '客户不能直接联系裁缝或制作团队；具体修改需求由客服收集并内部转交评估。',
                '商品生产国家使用独立的 ask_product_origin verified 策略；若同一问题同时询问产地与联系方式，回复时组合两条策略。',
                '网站真实性和下单故障分别使用 ask_guarantee 与 ask_how_to_order，不纳入 request_contact。',
                '回复模板统一使用英文；如客户使用其他语言，先完成英文事实校验后再翻译。',
            ];
            $reviewNotes = '业务确认：当前站点仅在线经营，无线下门店或试穿地点；使用已配置的 Live Chat、客服邮箱和 Contact Us 页面。产地问题继续使用独立的 ask_product_origin verified 策略，组合问题在回复阶段调用两条策略。';

            DB::table('product_question_policy_candidates')->where('id', $candidate->id)->update([
                'policy_rules' => $this->json($policyRules),
                'open_questions' => '[]',
                'confidence' => 0.9500,
                'review_status' => 'confirmed',
                'review_notes' => $reviewNotes,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ]);

            $candidate = DB::table('product_question_policy_candidates')->where('id', $candidate->id)->first();
            $siteKey = DB::table('commerce_sites')->where('id', $candidate->commerce_site_id)->value('site_key');
            if (! is_string($siteKey) || $siteKey === '') {
                return;
            }

            $key = 'qa_'.$siteKey.'_request_contact';
            $existing = DB::table('knowledge_articles')->where('key', $key)->first();
            $articleValues = [
                'title' => $candidate->title,
                'knowledge_type' => 'policy',
                'status' => 'verified',
                'site_key' => $siteKey,
                'applicability' => $candidate->applicability,
                'required_facts' => $candidate->required_facts,
                'policy_rules' => $candidate->policy_rules,
                'decision_tree' => $candidate->decision_tree,
                'reply_strategy' => $candidate->reply_strategy,
                'reply_templates' => $candidate->reply_templates,
                'action_steps' => $candidate->action_steps,
                'prohibited_claims' => $candidate->prohibited_claims,
                'escalation_rules' => $candidate->escalation_rules,
                'source_notes' => '问答策略 #22，证据站点 '.$siteKey.'，人工确认；证据 '.count($this->jsonList($candidate->evidence_question_ids)).' 条。产地事实由 qa_global_ask_product_origin 独立维护。',
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
