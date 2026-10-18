<?php

namespace App\Services\Work;

use App\Models\CustomerCase;
use App\Models\CustomerCaseHandoff;
use App\Models\CustomerCaseSource;
use App\Models\ProductQuestion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CustomerCaseManager
{
    public function forProductQuestion(ProductQuestion $question): CustomerCase
    {
        $question->loadMissing('site', 'analysis');
        $case = CustomerCase::query()->firstOrNew([
            'case_key' => "product-question:{$question->commerce_site_id}:{$question->external_id}",
        ]);
        $case->fill([
            'site_key' => $question->site->site_key,
            'case_type' => 'product_question',
            'title' => mb_substr(trim($question->question) ?: "Product question {$question->external_id}", 0, 255),
            'risk_level' => $question->analysis?->risk_level ?? 'normal',
            'priority' => $case->priority ?? 'normal',
            'queue' => $case->queue ?? 'product_questions',
            'metadata' => ['external_question_id' => $question->external_id],
        ]);
        if (! $case->exists) {
            $case->status = 'open';
        }
        if ($question->reply_status === 'answered') {
            $case->status = 'resolved';
            $case->resolved_at = $question->replied_at ?? now();
        }
        $case->save();
        $this->linkSource($case, 'product_question', (string) $question->id, [
            'external_id' => $question->external_id,
            'product_sn' => $question->sn,
        ]);

        return $case;
    }

    public function linkSource(
        CustomerCase $case,
        string $sourceType,
        string $sourceId,
        array $snapshot = [],
        ?string $customerIdentity = null,
    ): CustomerCaseSource {
        $existing = CustomerCaseSource::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
        if ($existing !== null && $existing->customer_case_id !== $case->id) {
            throw new RuntimeException('The source is already linked to another customer Case.');
        }

        return CustomerCaseSource::query()->updateOrCreate(
            ['source_type' => $sourceType, 'source_id' => $sourceId],
            [
                'customer_case_id' => $case->id,
                'customer_identity_hash' => $customerIdentity === null
                    ? null
                    : hash_hmac('sha256', mb_strtolower(trim($customerIdentity)), (string) config('app.key')),
                'snapshot' => $snapshot,
            ],
        );
    }

    public function handoff(
        CustomerCase $case,
        ?string $queue,
        ?int $ownerId,
        string $reason,
        ?int $createdBy = null,
    ): CustomerCase {
        if (trim($reason) === '') {
            throw new RuntimeException('A handoff reason is required.');
        }

        return DB::transaction(function () use ($case, $queue, $ownerId, $reason, $createdBy): CustomerCase {
            CustomerCaseHandoff::create([
                'customer_case_id' => $case->id,
                'from_queue' => $case->queue,
                'from_owner_id' => $case->owner_id,
                'to_queue' => $queue,
                'to_owner_id' => $ownerId,
                'reason' => trim($reason),
                'created_by' => $createdBy,
            ]);
            $case->update(['queue' => $queue, 'owner_id' => $ownerId]);

            return $case->refresh();
        });
    }

    public function recordOutcome(CustomerCase $case, string $type, array $outcome): CustomerCase
    {
        $case->update([
            'status' => 'resolved',
            'outcome_type' => $type,
            'outcome' => $outcome,
            'resolved_at' => now(),
        ]);

        return $case->refresh();
    }
}
