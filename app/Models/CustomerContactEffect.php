<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerContactEffect extends Model
{
    protected $guarded = [];

    protected $hidden = ['payload', 'preview'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'preview' => 'array', 'receipt' => 'array',
            'authorized_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'attempt_started_at' => 'immutable_datetime'];
    }
}
