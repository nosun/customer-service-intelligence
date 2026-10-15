<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceProduct extends Model
{
    protected $guarded = [];

    public function site(): BelongsTo
    {
        return $this->belongsTo(CommerceSite::class, 'commerce_site_id');
    }

    protected function casts(): array
    {
        return [
            'product' => 'array',
            'product_detail' => 'array',
            'images' => 'array',
            'options' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
