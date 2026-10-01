<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelSyncRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'filter_snapshot' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
