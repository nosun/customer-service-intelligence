<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelAccount extends Model
{
    protected $guarded = [];

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }
}
