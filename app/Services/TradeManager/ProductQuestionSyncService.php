<?php

namespace App\Services\TradeManager;

use App\Models\CommerceSite;
use App\Models\ProductQuestion;
use App\Models\ProductQuestionSyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProductQuestionSyncService
{
    public function __construct(private ProductQuestionClient $client) {}

    /**
     * @param  null|callable(int, int, int): void  $progress  Receives page, last page and records seen.
     * @return array{run_id: int, mode: string, pages: int, reported_total: int, records_seen: int, records_saved: int, missing_marked: int}
     */
    public function sync(
        CommerceSite $site,
        int $perPage = 100,
        ?callable $progress = null,
        bool $full = false,
    ): array {
        if (! $site->enabled) {
            throw new RuntimeException("Site {$site->site_key} is disabled.");
        }
        if ($perPage < 1 || $perPage > 100) {
            throw new RuntimeException('per_page must be between 1 and 100.');
        }

        $cursor = $full ? null : $this->cursor($site);
        $mode = $cursor === null ? 'full' : 'incremental';
        $token = (string) Str::uuid();
        $run = ProductQuestionSyncRun::create([
            'commerce_site_id' => $site->id,
            'sync_token' => $token,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $site->update([
            'product_question_sync_status' => 'running',
            'product_question_sync_error' => null,
        ]);

        $page = 1;
        $lastPage = 1;
        $reportedTotal = null;
        $seenIds = [];
        $saved = 0;
        $lastRemoteCursor = $cursor;

        try {
            do {
                $filters = [
                    'page' => $page,
                    'per_page' => $perPage,
                ];
                if ($cursor !== null) {
                    $filters['updated_after'] = $cursor['updated_at']->subMinutes(5)->toIso8601String();
                    $filters['after_id'] = 0;
                }
                $result = $this->client->questions($site, $filters);
                $items = $result['items'] ?? null;
                $pagination = $result['pagination'] ?? null;
                if (! is_array($items) || ! is_array($pagination)) {
                    throw new RuntimeException("Page {$page} returned an invalid question payload.");
                }

                $currentTotal = (int) ($pagination['total'] ?? -1);
                $currentPage = (int) ($pagination['current_page'] ?? -1);
                $currentLastPage = (int) ($pagination['last_page'] ?? -1);
                if ($currentPage !== $page || $currentTotal < 0 || $currentLastPage < 1) {
                    throw new RuntimeException("Page {$page} returned invalid pagination metadata.");
                }
                if ($reportedTotal !== null && $reportedTotal !== $currentTotal) {
                    throw new RuntimeException('Question total changed during synchronization; retry from page 1.');
                }
                $reportedTotal ??= $currentTotal;
                $lastPage = $currentLastPage;

                foreach ($items as $item) {
                    $externalId = $item['id'] ?? null;
                    if (! is_int($externalId) && ! ctype_digit((string) $externalId)) {
                        throw new RuntimeException("Page {$page} contains a question without a valid id.");
                    }
                    $externalId = (int) $externalId;
                    if (isset($seenIds[$externalId])) {
                        throw new RuntimeException("Question {$externalId} was returned more than once.");
                    }
                    $seenIds[$externalId] = true;

                    $updatedAt = $item['updated_at'] ?? null;
                    if (! is_string($updatedAt) || trim($updatedAt) === '') {
                        throw new RuntimeException("Question {$externalId} is missing updated_at.");
                    }
                    try {
                        $remoteUpdatedAt = CarbonImmutable::parse($updatedAt);
                    } catch (Throwable) {
                        throw new RuntimeException("Question {$externalId} contains an invalid updated_at.");
                    }
                    if ($lastRemoteCursor === null
                        || $remoteUpdatedAt->greaterThan($lastRemoteCursor['updated_at'])
                        || ($remoteUpdatedAt->equalTo($lastRemoteCursor['updated_at'])
                            && $externalId > $lastRemoteCursor['id'])) {
                        $lastRemoteCursor = ['updated_at' => $remoteUpdatedAt, 'id' => $externalId];
                    }
                }

                DB::transaction(function () use ($site, $items, $token, &$saved): void {
                    foreach ($items as $item) {
                        $this->storeQuestion($site, $item, $token);
                        $saved++;
                    }
                });

                if ($progress) {
                    $progress($page, $lastPage, count($seenIds));
                }
                $page++;
            } while ($page <= $lastPage);

            $reportedTotal ??= 0;
            if (count($seenIds) !== $reportedTotal) {
                throw new RuntimeException(
                    "Synchronization count mismatch: API reported {$reportedTotal}, received ".count($seenIds).'.',
                );
            }

            $missingMarked = 0;
            if ($mode === 'full') {
                $missingMarked = ProductQuestion::query()
                    ->where('commerce_site_id', $site->id)
                    ->where('remote_present', true)
                    ->where(function ($query) use ($token): void {
                        $query->whereNull('last_seen_sync_token')->orWhere('last_seen_sync_token', '!=', $token);
                    })
                    ->update(['remote_present' => false, 'updated_at' => now()]);
            }

            $finishedAt = now();
            $run->update([
                'status' => 'completed',
                'pages' => $lastPage,
                'reported_total' => $reportedTotal,
                'records_seen' => count($seenIds),
                'records_saved' => $saved,
                'missing_marked' => $missingMarked,
                'finished_at' => $finishedAt,
            ]);
            $siteValues = [
                'product_question_sync_status' => 'completed',
                'product_question_sync_error' => null,
                'product_questions_last_synced_at' => $finishedAt,
            ];
            if ($lastRemoteCursor !== null) {
                $siteValues['product_questions_cursor_updated_at'] = $lastRemoteCursor['updated_at'];
                $siteValues['product_questions_cursor_id'] = $lastRemoteCursor['id'];
            }
            $site->update($siteValues);

            return [
                'run_id' => $run->id,
                'mode' => $mode,
                'pages' => $lastPage,
                'reported_total' => $reportedTotal,
                'records_seen' => count($seenIds),
                'records_saved' => $saved,
                'missing_marked' => $missingMarked,
            ];
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'pages' => max(0, $page - 1),
                'reported_total' => (int) ($reportedTotal ?? 0),
                'records_seen' => count($seenIds),
                'records_saved' => $saved,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
            $site->update([
                'product_question_sync_status' => 'failed',
                'product_question_sync_error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /** @return null|array{updated_at: CarbonImmutable, id: int} */
    private function cursor(CommerceSite $site): ?array
    {
        if ($site->product_questions_cursor_updated_at !== null
            && $site->product_questions_cursor_id !== null) {
            return [
                'updated_at' => CarbonImmutable::instance($site->product_questions_cursor_updated_at),
                'id' => (int) $site->product_questions_cursor_id,
            ];
        }

        if ($site->product_question_sync_status !== 'completed'
            || $site->product_questions_last_synced_at === null) {
            return null;
        }

        $latest = ProductQuestion::query()
            ->where('commerce_site_id', $site->id)
            ->whereNotNull('remote_updated_at')
            ->orderByDesc('remote_updated_at')
            ->orderByDesc('external_id')
            ->first(['external_id', 'remote_updated_at']);

        if ($latest === null) {
            return null;
        }

        return [
            'updated_at' => CarbonImmutable::instance($latest->remote_updated_at),
            'id' => (int) $latest->external_id,
        ];
    }

    /** @param array<string, mixed> $item */
    public function storeQuestion(CommerceSite $site, array $item, ?string $syncToken = null): ProductQuestion
    {
        $externalId = (int) ($item['id'] ?? 0);
        if ($externalId < 1) {
            throw new RuntimeException('Question payload is missing a valid id.');
        }

        $values = [
            'sn' => (string) ($item['sn'] ?? ''),
            'product' => is_array($item['product'] ?? null) ? $item['product'] : null,
            'user_id' => (int) ($item['user_id'] ?? 0),
            'name' => (string) ($item['name'] ?? ''),
            'email' => (string) ($item['email'] ?? ''),
            'type' => (int) ($item['type'] ?? 0),
            'type_text' => (string) ($item['type_text'] ?? ''),
            'question' => (string) ($item['question'] ?? ''),
            'answer' => $item['answer'] ?? null,
            'reply_status' => (string) ($item['reply_status'] ?? 'unanswered'),
            'needs_reply' => (bool) ($item['needs_reply'] ?? false),
            'visible' => (bool) ($item['visible'] ?? false),
            'notice_status' => (int) ($item['notice_status'] ?? 0),
            'notice_status_text' => (string) ($item['notice_status_text'] ?? ''),
            'images' => is_array($item['images'] ?? null) ? $item['images'] : [],
            'weight' => (int) ($item['weight'] ?? 0),
            'helpful' => max(0, (int) ($item['helpful'] ?? 0)),
            'status' => (int) ($item['status'] ?? 1),
            'status_text' => (string) ($item['status_text'] ?? ''),
            'reply_operator' => is_array($item['reply_operator'] ?? null) ? $item['reply_operator'] : null,
            'reply_source' => is_string(data_get($item, 'reply_operator.source')) ? data_get($item, 'reply_operator.source') : null,
            'replied_at' => is_string(data_get($item, 'reply_operator.replied_at')) ? data_get($item, 'reply_operator.replied_at') : null,
            'close_reason' => is_string($item['close_reason'] ?? null) ? $item['close_reason'] : null,
            'close_operator' => is_array($item['close_operator'] ?? null) ? $item['close_operator'] : null,
            'remote_created_at' => $item['created_at'] ?? null,
            'remote_updated_at' => $item['updated_at'] ?? null,
            'raw_payload' => $item,
            'remote_present' => true,
            'synced_at' => now(),
        ];
        if ($syncToken !== null) {
            $values['last_seen_sync_token'] = $syncToken;
        }

        return ProductQuestion::updateOrCreate(
            [
                'commerce_site_id' => $site->id,
                'external_id' => $externalId,
            ],
            $values,
        );
    }
}
