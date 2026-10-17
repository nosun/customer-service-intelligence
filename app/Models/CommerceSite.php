<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommerceSite extends Model
{
    protected $guarded = [];

    protected $hidden = ['api_token'];

    public function productQuestions(): HasMany
    {
        return $this->hasMany(ProductQuestion::class);
    }

    public function productQuestionSyncRuns(): HasMany
    {
        return $this->hasMany(ProductQuestionSyncRun::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(CommerceProduct::class);
    }

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'enabled' => 'boolean',
            'operates_online_only' => 'boolean',
            'live_chat_enabled' => 'boolean',
            'product_questions_last_synced_at' => 'datetime',
            'product_questions_cursor_updated_at' => 'datetime',
        ];
    }
}
