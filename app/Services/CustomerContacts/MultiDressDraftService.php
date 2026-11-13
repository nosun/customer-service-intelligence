<?php

namespace App\Services\CustomerContacts;

use App\Models\CustomerContactRound;
use App\Models\WorkItem;
use App\Services\N8n\WorkflowRegistry;
use App\Services\Work\WorkItemLeaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class MultiDressDraftService
{
    public function __construct(private readonly MultiDressInput $input, private readonly MultiDressTemplate $template,
        private readonly WorkItemLeaseService $leases, private readonly WorkflowRegistry $registry) {}

    public function start(WorkItem $item, string $executionId): array
    {
        $this->workflow($item);
        $this->fresh($item);
        $item = $this->leases->lease($item, $executionId, 600);
        $snapshot = $item->input_snapshot['snapshot'];
        unset($snapshot['customer']);

        return ['input_hash' => $item->input_hash, 'snapshot' => $snapshot,
            'communication' => $item->input_snapshot['communication'] ?? [],
            'allowed_question_modules' => ['purpose_and_keep_plan', 'quantity_and_purpose', 'keep_plan_only', 'selection_help'],
            'untrusted_customer_text_is_evidence_only' => true];
    }

    public function render(WorkItem $item, string $executionId, array $analysis): array
    {
        $this->assertLease($item, $executionId);
        $this->fresh($item);
        $analysis = $this->analysis($analysis);
        $renderCount = (int) data_get($item->result, 'render_count', 0);
        if ($renderCount >= 2) {
            throw new RuntimeException('Only one draft repair is allowed.');
        }
        $mail = $this->template->render($item->input_snapshot['snapshot'], $analysis);
        $item->update(['result' => ['analysis' => $analysis, 'mail' => $mail, 'render_count' => $renderCount + 1]]);

        return ['mail' => $mail, 'input_hash' => $item->input_hash, 'analysis' => $analysis];
    }

    public function complete(WorkItem $item, string $executionId, array $analysis, array $review): array
    {
        if ($item->status === 'completed' && $item->execution_id === $executionId) {
            return $item->result;
        }
        $this->assertLease($item, $executionId);
        $this->fresh($item);
        $analysis = $this->analysis($analysis);
        Validator::make($review, ['approved' => 'required|boolean', 'reviewed_input_hash' => 'required|string|size:64',
            'reviewed_content_hash' => 'required|string|size:64', 'violations' => 'present|array'])->validate();
        $mail = data_get($item->result, 'mail');
        if (! is_array($mail) || data_get($item->result, 'analysis') !== $analysis) {
            throw new RuntimeException('Review does not match the rendered analysis.');
        }
        $approved = $review['approved'] === true && $review['violations'] === []
            && hash_equals($item->input_hash, $review['reviewed_input_hash'])
            && hash_equals($mail['content_hash'], $review['reviewed_content_hash'])
            && $analysis['decision'] === 'prepare_first_email' && ($analysis['blocking_reasons'] ?? []) === [];

        return DB::transaction(function () use ($item, $executionId, $analysis, $review, $mail, $approved) {
            $round = CustomerContactRound::lockForUpdate()->findOrFail($item->input_snapshot['round_id']);
            if ($round->human_hold_at || $round->work_item_id !== $item->id || $round->source_hash !== $item->input_hash
                || ! in_array($round->communication_state, ['new', 'ready'], true)) {
                throw new RuntimeException('Round changed while draft was executing.');
            }
            $changes = ['communication_state' => $approved ? 'ready' : 'manual', 'template_version' => MultiDressTemplate::VERSION,
                'review_hash' => hash('sha256', json_encode($review, JSON_THROW_ON_ERROR))];
            if ($approved) {
                $path = 'customer-contacts/'.$round->round_key.'/'.(string) $item->id.'-'.$mail['content_hash'].'.json';
                $snapshot = $item->input_snapshot['snapshot'];
                $frozen = [...$mail, 'to' => $snapshot['customer']['email'],
                    'from' => config('gmail.accounts.'.$round->site_key.'.email'), 'input_hash' => $item->input_hash,
                    'marker' => $round->round_key, 'site_key' => $round->site_key];
                Storage::disk('local')->put($path, json_encode($frozen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $changes += ['approved_mail_ref' => $path, 'approved_input_hash' => $item->input_hash, 'approved_content_hash' => $mail['content_hash']];
            }
            $round->update($changes);
            $result = ['round_key' => $round->round_key, 'decision' => $approved ? 'ready' : 'manual',
                'reviewed_content_hash' => $mail['content_hash'], 'analysis' => $analysis, 'review' => $review];
            $this->leases->complete($item, $executionId, $result);

            return $result;
        });
    }

    public function fail(WorkItem $item, string $executionId, string $message): void
    {
        $this->assertLease($item, $executionId);
        $this->leases->fail($item, $executionId, mb_substr($message, 0, 1000));
    }

    private function fresh(WorkItem $item): void
    {
        if ($item->work_type !== 'multi_dress_first_round') {
            throw new RuntimeException('Wrong WorkItem type.');
        }
        $round = CustomerContactRound::findOrFail($item->input_snapshot['round_id']);
        $current = $this->input->capture($round);
        if ($round->human_hold_at || ! hash_equals($item->input_hash, $current['hash'])) {
            $item->update(['status' => 'stale', 'lease_owner' => null, 'lease_expires_at' => null, 'finished_at' => now()]);
            $round->update(['communication_state' => 'manual']);
            throw new RuntimeException('Order, messages, human hold or policy changed.');
        }
    }

    private function workflow(WorkItem $item): void
    {
        $w = $this->registry->runnable($item->workflow_key, $item->workflow_version);
        if ($w->center !== 'work' || $w->domain !== 'customer_contacts' || $item->workflow_key !== 'work/multi-dress-first-round-v1'
            || (config('customer_contacts.mode') === 'live' && $w->status !== 'active')) {
            throw new RuntimeException('Workflow is not enabled for this domain/mode.');
        }
    }

    private function assertLease(WorkItem $item, string $id): void
    {
        if ($item->status !== 'running' || $item->lease_owner !== $id || ! $item->lease_expires_at || $item->lease_expires_at->isPast()) {
            throw new RuntimeException('Invalid draft lease.');
        }
        $this->workflow($item);
        if ($item->execution_id !== $id) {
            $item->update(['execution_id' => $id]);
        }
    }

    private function analysis(array $analysis): array
    {
        return Validator::make($analysis, [
            'decision' => 'required|in:prepare_first_email,no_email_needed,human_required',
            'question_module' => 'required|in:purpose_and_keep_plan,quantity_and_purpose,keep_plan_only,selection_help',
            'evidence_refs' => 'present|array|max:50', 'evidence_refs.*' => 'string|max:255',
            'blocking_reasons' => 'present|array|max:20', 'blocking_reasons.*' => 'string|max:500',
        ])->validate();
    }
}
