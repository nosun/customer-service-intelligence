<?php

namespace App\Services\ProductQuestions;

use App\Models\CommerceProduct;
use App\Models\KnowledgeArticle;
use App\Models\ProductQuestion;

class ProductQuestionDraftInput
{
    /** @return array{hash:string,snapshot:array<string,mixed>} */
    public function capture(
        ProductQuestion $question,
        string $workflowKey = N8nProductQuestionDraftService::WORKFLOW_KEY,
        string $workflowVersion = N8nProductQuestionDraftService::WORKFLOW_VERSION,
        string $promptVersion = N8nProductQuestionDraftService::PROMPT_VERSION,
    ): array {
        $question->loadMissing('site', 'analysis');
        $product = CommerceProduct::query()
            ->where('commerce_site_id', $question->commerce_site_id)
            ->where('sn', $question->sn)
            ->first();
        $siteKey = $question->site->site_key;
        $policies = KnowledgeArticle::query()
            ->where('status', 'verified')
            ->where(function ($query) use ($siteKey): void {
                $query->where('key', 'like', 'qa_global_%')
                    ->orWhere('key', 'like', 'qa_'.$siteKey.'_%');
            })
            ->orderBy('key')
            ->get(['id', 'key', 'version', 'updated_at'])
            ->map(fn (KnowledgeArticle $article): array => [
                'id' => $article->id,
                'key' => $article->key,
                'version' => $article->version,
                'updated_at' => $article->updated_at?->toIso8601String(),
            ])->all();

        $snapshot = [
            'question' => [
                'id' => $question->id,
                'text' => $question->question,
                'sn' => $question->sn,
                'reply_status' => $question->reply_status,
                'remote_present' => $question->remote_present,
                'remote_updated_at' => $question->remote_updated_at?->toIso8601String(),
                'updated_at' => $question->updated_at?->toIso8601String(),
                'replay_fixture_hash' => $question->name === '__CSI_N8N_REPLAY__'
                    && is_array(data_get($question->raw_payload, '_replay.fixture'))
                    ? hash('sha256', json_encode(data_get($question->raw_payload, '_replay.fixture'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
                    : null,
            ],
            'analysis' => $question->analysis === null ? null : [
                'id' => $question->analysis->id,
                'updated_at' => $question->analysis->updated_at?->toIso8601String(),
            ],
            'product' => $product === null ? null : [
                'id' => $product->id,
                'payload_hash' => $product->payload_hash,
                'updated_at' => $product->updated_at?->toIso8601String(),
            ],
            'site' => [
                'id' => $question->site->id,
                'site_key' => $siteKey,
                'public_base_url' => $question->site->public_base_url,
                'brand_name' => $question->site->brand_name,
                'customer_service_email' => $question->site->customer_service_email,
                'updated_at' => $question->site->updated_at?->toIso8601String(),
            ],
            'policies' => $policies,
            'workflow' => [
                'key' => $workflowKey,
                'version' => $workflowVersion,
                'prompt_version' => $promptVersion,
                'provider' => 'minimax',
                'model' => (string) config('services.minimax.model'),
            ],
        ];

        return [
            'hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'snapshot' => $snapshot,
        ];
    }
}
