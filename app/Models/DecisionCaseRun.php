<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DecisionCaseRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'structured_output' => 'array',
            'usage' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
