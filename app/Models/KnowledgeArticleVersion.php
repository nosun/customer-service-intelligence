<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeArticleVersion extends Model
{
    protected $guarded = [];

    public function article(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'knowledge_article_id');
    }

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
