<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerContactEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
