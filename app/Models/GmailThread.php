<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GmailThread extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_message_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }
}
