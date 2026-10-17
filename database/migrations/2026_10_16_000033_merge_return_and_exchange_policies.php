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
            DB::table('product_question_analyses')
                ->where('intent_key', 'ask_exchange_policy')
                ->update([
                    'intent_key' => 'ask_return_policy',
                    'updated_at' => now(),
                ]);

            $returnPolicy = DB::table('product_question_policy_candidates')->where('id', 15)->first();
            $exchangePolicy = DB::table('product_question_policy_candidates')->where('id', 24)->first();
            if ($returnPolicy === null || $exchangePolicy === null) {
                return;
            }

            $now = now();
            $reviewerId = DB::table(config('admin.database.users_table'))->min('id');
            $evidence = array_values(array_unique(array_map('intval', [
                ...$this->jsonList($returnPolicy->evidence_question_ids),
                ...$this->jsonList($exchangePolicy->evidence_question_ids),
            ])));
            $content = $this->mergedContent($returnPolicy);

            DB::table('product_question_policy_candidates')->where('id', $returnPolicy->id)->update([
                ...$content,
                'scenario_key' => 'ask_return_policy',
                'evidence_question_ids' => $this->json($evidence),
                'conflicts' => '[]',
                'open_questions' => '[]',
                'confidence' => 0.9500,
                'review_status' => 'confirmed',
                'review_notes' => '业务确认：Policy 15 与 Policy 24 合并为统一退货与换码策略。一般退货、换码及购买后改尺码统一归 ask_return_policy；不提供 direct exchange，符合条件时采用 return + new order。实际质量、错发、退款或补偿投诉仍转 ask_refund。',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ]);

            if ($returnPolicy->knowledge_article_id !== null) {
                $article = DB::table('knowledge_articles')->where('id', $returnPolicy->knowledge_article_id)->first();
                if ($article !== null) {
                    $articleContent = $content;
                    unset($articleContent['definition']);
                    DB::table('knowledge_articles')->where('id', $article->id)->update([
                        ...$articleContent,
                        'key' => 'qa_global_ask_return_policy',
                        'knowledge_type' => 'policy',
                        'status' => 'verified',
                        'site_key' => null,
                        'source_notes' => '由问答策略 #15 与 #24 合并；证据 '.count($evidence).' 条；完整条款依据 docs/policies/dressafford-cancellation-and-return-policy.md。',
                        'version' => ((int) $article->version) + 1,
                        'reviewed_by' => $reviewerId,
                        'reviewed_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $this->replaceEvidence((int) $article->id, $evidence, $now);
                }
            }

            DB::table('product_question_policy_candidates')->where('id', $exchangePolicy->id)->update([
                'review_status' => 'rejected',
                'review_notes' => '已合并至 ask_return_policy（策略 #15）。换码不是独立政策：网站不提供 direct exchange，符合条件时按退货后重新下单处理。本记录仅保留用于历史审计。',
                'knowledge_article_id' => null,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ]);

            if ($exchangePolicy->knowledge_article_id !== null) {
                DB::table('product_question_strategy_evidence')
                    ->where('knowledge_article_id', $exchangePolicy->knowledge_article_id)
                    ->delete();
                DB::table('knowledge_articles')->where('id', $exchangePolicy->knowledge_article_id)->update([
                    'status' => 'deprecated',
                    'source_notes' => '已合并并由 qa_global_ask_return_policy 替代；原证据已迁移至统一退货与换码策略。',
                    'updated_at' => $now,
                ]);
            }

            $this->normalizeComplaintPolicyReferences($now);
        });
    }

    public function down(): void
    {
        // The original split cannot be reconstructed after new analyses use the unified intent.
    }

    /** @return array<string, string> */
    private function mergedContent(object $returnPolicy): array
    {
        $requiredFacts = $this->jsonList($returnPolicy->required_facts);
        $requiredFacts[] = '客户是在询问一般退货，还是尺码不合适后的换码方式或新订单到货时间';

        $policyRules = $this->jsonList($returnPolicy->policy_rules);
        $policyRules[] = '不提供 direct exchange；符合退货条件的原商品须先申请退货，所需尺码另行下新订单，并按下单时的商品 ETA 评估到货时间。';

        $decisionTree = collect($this->jsonList($returnPolicy->decision_tree))
            ->reject(fn (mixed $branch): bool => is_array($branch)
                && str_contains((string) ($branch['condition'] ?? ''), '换码'))
            ->values()
            ->all();
        $decisionTree[] = [
            'condition' => '客户询问尺码不合适时能否换码、换货或改成其他尺码',
            'outcome' => '说明不提供 direct exchange；符合条件时申请退回原商品并另行下新订单。若询问新订单到货时间，转当前商品 ETA 策略。',
        ];

        $replyStrategy = $this->jsonList($returnPolicy->reply_strategy);
        $replyStrategy[] = [
            'stage' => '处理换码',
            'goal' => '明确换码与退货使用同一政策路径',
            'content_requirements' => ['无 direct exchange', '符合条件时 return + new order', '新订单 ETA 需实时查询'],
        ];

        $replyTemplates = $this->jsonList($returnPolicy->reply_templates);
        $replyTemplates[] = [
            'conditions' => ['客户询问尺码不合适能否换码或换货'],
            'body' => 'We do not offer direct exchanges. If an eligible item does not fit, you may request a return and place a separate new order for the size you need. Please contact {{ site.customer_service_email }} within 14 days of receiving the item to request return approval. You can review the complete policy at {{ site.public_base_url }}/article/return-policy.html.',
        ];

        $actionSteps = $this->jsonList($returnPolicy->action_steps);
        $actionSteps[] = '换码或购买后改尺码按 ask_return_policy 回复；如需估算新订单到货时间，调用当前商品 ETA 策略。';

        $prohibitedClaims = $this->jsonList($returnPolicy->prohibited_claims);
        $prohibitedClaims[] = '不得声称提供 direct exchange、可直接换码或存在可供换货的现货。';

        $escalationRules = $this->jsonList($returnPolicy->escalation_rules);
        $escalationRules[] = '客户要求特批直接换货、免除退货条件，或对新订单时效作确定承诺。';

        return [
            'title' => '退货与换码政策咨询',
            'definition' => '客户询问一般退货资格、申请和寄回时间、商品状态、退款范围、不可退换类别，或尺码不合适后的换码与重新下单方式。',
            'applicability' => '适用于一般退货、换码及购买后改尺码的政策解释；统一使用 ask_return_policy。已发生的质量、错发、退款、补偿或投诉使用 ask_refund 并转人工售后。',
            'required_facts' => $this->json(array_values(array_unique($requiredFacts))),
            'policy_rules' => $this->json(array_values(array_unique($policyRules))),
            'decision_tree' => $this->json($decisionTree),
            'reply_strategy' => $this->json($replyStrategy),
            'reply_templates' => $this->json($replyTemplates),
            'action_steps' => $this->json(array_values(array_unique($actionSteps))),
            'prohibited_claims' => $this->json(array_values(array_unique($prohibitedClaims))),
            'escalation_rules' => $this->json(array_values(array_unique($escalationRules))),
        ];
    }

    /** @param list<int> $questionIds */
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

    private function normalizeComplaintPolicyReferences(mixed $now): void
    {
        $candidate = DB::table('product_question_policy_candidates')->where('id', 26)->first();
        if ($candidate === null) {
            return;
        }

        $fields = ['applicability', 'policy_rules', 'decision_tree', 'action_steps', 'review_notes'];
        $updates = ['updated_at' => $now];
        foreach ($fields as $field) {
            $updates[$field] = str_replace(
                [
                    '一般退货条款使用 ask_return_policy；换码使用 ask_exchange_policy',
                    '分别转入 ask_return_policy 或 ask_exchange_policy',
                    '一般退货条款使用 Policy 15，换码使用 Policy 24',
                ],
                [
                    '一般退货与换码条款统一使用 ask_return_policy',
                    '转入 ask_return_policy',
                    '一般退货与换码条款统一使用 Policy 15',
                ],
                (string) $candidate->{$field},
            );
        }
        DB::table('product_question_policy_candidates')->where('id', $candidate->id)->update($updates);

        if ($candidate->knowledge_article_id === null) {
            return;
        }

        $article = DB::table('knowledge_articles')->where('id', $candidate->knowledge_article_id)->first();
        if ($article === null) {
            return;
        }

        unset($updates['review_notes']);
        foreach (['applicability', 'policy_rules', 'decision_tree', 'action_steps'] as $field) {
            $updates[$field] = str_replace(
                ['一般退货条款使用 ask_return_policy；换码使用 ask_exchange_policy', '分别转入 ask_return_policy 或 ask_exchange_policy'],
                ['一般退货与换码条款统一使用 ask_return_policy', '转入 ask_return_policy'],
                (string) $article->{$field},
            );
        }
        DB::table('knowledge_articles')->where('id', $article->id)->update($updates);
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

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
};
