<?php

namespace Tests\Support;

use App\Models\AiWorkflow;
use App\Models\CustomerContactRound;
use App\Models\WorkItem;
use App\Services\CustomerContacts\MultiDressRoundManager;
use App\Services\CustomerContacts\MultiDressTemplate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MultiDressReadyRound
{
    public static function create(): CustomerContactRound
    {
        $s = MultiDressScenario::snapshot();
        $round = app(MultiDressRoundManager::class)->ingest($s, MultiDressScenario::communication());
        $mail = app(MultiDressTemplate::class)->render($s, ['question_module' => 'purpose_and_keep_plan']);
        $path = 'customer-contacts/'.$round->round_key.'/test-mail.json';
        Storage::disk('local')->put($path, json_encode([...$mail, 'to' => $s['customer']['email'],
            'from' => config('gmail.accounts.ellenbridals.email'), 'site_key' => 'ellenbridals',
            'input_hash' => $round->source_hash, 'marker' => $round->round_key]));
        $review = ['approved' => true, 'violations' => [], 'reviewed_input_hash' => $round->source_hash, 'reviewed_content_hash' => $mail['content_hash']];
        $round->update(['communication_state' => 'ready', 'approved_mail_ref' => $path,
            'approved_input_hash' => $round->source_hash, 'approved_content_hash' => $mail['content_hash'], 'review_hash' => hash('sha256', json_encode($review))]);
        AiWorkflow::firstOrCreate(['workflow_key' => 'work/multi-dress-first-round-v1', 'workflow_version' => '1.0.0'],
            ['center' => 'work', 'domain' => 'customer_contacts', 'status' => 'active',
                'definition_checksum' => hash_file('sha256', base_path('n8n/workflows/work__multi-dress-first-round-v1.json'))]);
        $work = WorkItem::create(['work_item_key' => (string) Str::uuid(),
            'customer_case_id' => $round->customer_case_id, 'work_type' => 'multi_dress_first_round', 'status' => 'completed',
            'workflow_key' => 'work/multi-dress-first-round-v1', 'workflow_version' => '1.0.0',
            'input_hash' => $round->source_hash, 'input_snapshot' => ['snapshot' => $s], 'result' => ['decision' => 'ready', 'review' => $review]]);
        $round->update(['work_item_id' => $work->id]);

        return $round->refresh();
    }
}
