<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class N8nWorkflowExecution extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
