<?php

namespace App\Console\Commands;

use App\Models\CustomerContactEffect;
use App\Models\CustomerContactRound;
use App\Models\WorkItem;
use App\Services\CustomerContacts\MultiDressConsoleExecutor;
use App\Services\CustomerContacts\MultiDressMailExecutor;
use App\Services\N8n\N8nMultiDressWorkflowClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProcessMultiDressEffects extends Command
{
    protected $signature = 'contacts:multi-dress-process-effects {--limit=20}';

    protected $description = 'Process queued first-round draft work and reconcile uncertain sends';

    public function handle(MultiDressMailExecutor $mail): int
    {
        if (! Schema::hasTable('customer_contact_rounds') || ! in_array(config('customer_contacts.mode'), ['shadow', 'live'], true)) {
            return self::SUCCESS;
        }
        $limit = min(100, max(1, (int) $this->option('limit')));
        WorkItem::where('work_type', 'multi_dress_first_round')->where('status', 'running')->where('lease_expires_at', '<', now())
            ->update(['status' => 'failed', 'lease_owner' => null, 'lease_expires_at' => null, 'error_message' => 'Draft lease expired.']);
        foreach (WorkItem::where('work_type', 'multi_dress_first_round')->where('status', 'queued')->limit($limit)->get() as $item) {
            try {
                app(N8nMultiDressWorkflowClient::class)->trigger($item);
            } catch (Throwable) {
                $item->update(['status' => 'failed', 'error_message' => 'Workflow trigger failed; inspect configuration before retry.']);
            }
        }
        foreach (CustomerContactEffect::where('effect_kind', 'first_email')->whereIn('state', ['unknown', 'sending'])
            ->where('attempt_started_at', '<', now()->subMinutes(5))->limit($limit)->get() as $effect) {
            $mail->reconcile($effect->id);
        }
        if (config('customer_contacts.mode') === 'live' && config('customer_contacts.send_enabled')) {
            foreach (CustomerContactRound::where('communication_state', 'ready')->whereNull('human_hold_at')->limit($limit)->get() as $round) {
                try {
                    $mail->send($round->id);
                } catch (Throwable) {
                    $this->error('mail_preflight_failed:'.$round->id);
                }
            }
        }
        foreach (CustomerContactEffect::where('effect_kind', 'sent_note')->whereIn('state', ['unknown', 'sending'])->limit($limit)->get() as $effect) {
            try {
                app(MultiDressConsoleExecutor::class)->reconcile($effect->id);
            } catch (Throwable) {
                $this->error('note_reconcile_failed:'.$effect->id);
            }
        }

        return self::SUCCESS;
    }
}
