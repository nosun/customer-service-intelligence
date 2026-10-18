<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCaseSource extends Model
{
    protected $guarded = [];

    public function customerCase(): BelongsTo
    {
        return $this->belongsTo(CustomerCase::class);
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
