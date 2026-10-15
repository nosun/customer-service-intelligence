<?php

namespace App\Services\ProductQuestions;

use App\Models\KnowledgeArticle;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionPolicyCandidate;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductQuestionPolicyReviewer
{
    public function review(
        ProductQuestionPolicyCandidate $candidate,
        string $status,
        ?string $notes,
        string $scope,
        int $reviewerId,
    ): void {
        if ($candidate->review_status === 'confirmed' && $candidate->knowledge_article_id !== null) {
            if ($status !== 'confirmed') {
                throw new RuntimeException('已发布策略不能直接驳回；请通过知识版本流程废弃。');
            }

            return;
        }

        DB::transaction(function () use ($candidate, $status, $notes, $scope, $reviewerId): void {
            $candidate->update([
                'scope' => $scope,
                'review_status' => $status,
                'review_notes' => trim((string) $notes) ?: null,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);
            if ($status !== 'confirmed') {
                return;
            }

            $isGlobal = $scope === 'global';
            $key = 'qa_'.($isGlobal ? 'global' : $candidate->site->site_key).'_'.$candidate->scenario_key;
            $existingArticle = KnowledgeArticle::where('key', $key)->first();
            $article = KnowledgeArticle::updateOrCreate(
                ['key' => $key],
                [
                    'title' => $candidate->title,
                    'knowledge_type' => 'policy',
                    'status' => 'verified',
                    'site_key' => $isGlobal ? null : $candidate->site->site_key,
                    'applicability' => $candidate->applicability,
                    'required_facts' => $candidate->required_facts,
                    'policy_rules' => $candidate->policy_rules,
                    'decision_tree' => $candidate->decision_tree,
                    'reply_strategy' => $candidate->reply_strategy,
                    'reply_templates' => $candidate->reply_templates,
                    'action_steps' => $candidate->action_steps,
                    'prohibited_claims' => $candidate->prohibited_claims,
                    'escalation_rules' => $candidate->escalation_rules,
                    'source_notes' => "问答策略批次 {$candidate->run->batch_key}，证据站点 {$candidate->site->site_key}，人工确认；证据 ".count($candidate->evidence_question_ids).' 条。',
                    'version' => ($existingArticle?->version ?? 0) + 1,
                    'reviewed_by' => $reviewerId,
                    'reviewed_at' => now(),
                ],
            );
            DB::table('product_question_strategy_evidence')->where('knowledge_article_id', $article->id)->delete();
            foreach ($candidate->evidence_question_ids as $questionId) {
                $analysisId = ProductQuestion::find($questionId)?->analysis?->id;
                DB::table('product_question_strategy_evidence')->insert([
                    'knowledge_article_id' => $article->id,
                    'product_question_id' => $questionId,
                    'product_question_analysis_id' => $analysisId,
                    'evidence_role' => 'historical_example',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $candidate->update(['knowledge_article_id' => $article->id]);
        });
    }
}
