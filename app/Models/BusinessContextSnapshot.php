<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessContextSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'queried_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
