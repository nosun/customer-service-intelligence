<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductQuestionFactoryCapability extends Model
{
    public const STATUSES = [
        'pending' => '待询问工厂',
        'confirmed' => '工厂已确认',
        'rejected' => '工厂不支持',
        'needs_detail' => '需要逐款确认',
    ];

    protected $guarded = [];

    public function site(): BelongsTo
    {
        return $this->belongsTo(CommerceSite::class, 'commerce_site_id');
    }

    protected function casts(): array
    {
        return [
            'evidence_question_ids' => 'array',
            'product_sns' => 'array',
            'representative_questions' => 'array',
            'confidence' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }
}
