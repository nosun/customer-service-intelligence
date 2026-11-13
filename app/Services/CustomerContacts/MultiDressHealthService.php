<?php

namespace App\Services\CustomerContacts;

use App\Models\CustomerContactEffect;
use App\Models\CustomerContactRound;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class MultiDressHealthService
{
    public function report(CarbonImmutable $at): array
    {
        if (! Schema::hasTable('customer_contact_rounds')) {
            return ['installed' => false, 'unknown_sends' => 0, 'overdue' => 0, 'note_failures' => 0];
        }

        return ['installed' => true,
            'unknown_sends' => CustomerContactEffect::where('effect_kind', 'first_email')->whereIn('state', ['sending', 'unknown'])->count(),
            'overdue' => CustomerContactRound::whereIn('waiting_state', ['waiting', 'due'])->where('wait_until', '<=', $at)->count(),
            'note_failures' => CustomerContactRound::where('communication_state', 'sent')->whereIn('note_state', ['failed', 'unknown'])->count(),
            'held' => CustomerContactRound::where('waiting_state', 'held')->count(),
            'mode' => config('customer_contacts.mode')];
    }
}
