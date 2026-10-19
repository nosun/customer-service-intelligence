<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeArticleBinding extends Model
{
    public const CONSUMERS = [
        'product_question' => '商品公开问答',
        'ticket' => '私域客服 Ticket',
    ];

    public const USAGES = [
        'draft' => '回复草稿',
        'operation_guidance' => '人工操作指引',
    ];

    protected $guarded = [];

    public function article(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'knowledge_article_id');
    }

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}
