<?php

namespace App\Console\Commands;

use App\Models\BenchmarkCase;
use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SampleBenchmarkCases extends Command
{
    private const BUCKETS = ['presales', 'production', 'logistics', 'after_sales', 'unknown'];

    protected $signature = 'benchmark:sample
        {--count=10 : Number of cases to add}
        {--dataset=v1-pilot : Dataset version}
        {--split=pilot : pilot, development, validation, or test}
        {--seed=benchmark-v1 : Stable sampling seed}';

    protected $description = 'Select a deterministic stratified HelpDesk benchmark sample';

    public function handle(): int
    {
        $count = (int) $this->option('count');
        $dataset = trim((string) $this->option('dataset'));
        $split = trim((string) $this->option('split'));
        $seed = trim((string) $this->option('seed'));

        try {
            validator(compact('count', 'dataset', 'split', 'seed'), [
                'count' => ['required', 'integer', 'min:1', 'max:1000'],
                'dataset' => ['required', 'string', 'max:100'],
                'split' => ['required', Rule::in(['pilot', 'development', 'validation', 'test'])],
                'seed' => ['required', 'string', 'max:100'],
            ])->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existingIds = BenchmarkCase::where('dataset_version', $dataset)->pluck('conversation_id')->all();
        $selected = collect();
        $quotas = $this->quotas($count);

        foreach (self::BUCKETS as $bucketIndex => $bucket) {
            $candidates = $this->candidates($bucket, $existingIds, $seed);
            $chosen = $this->takeWithSiteDiversity($candidates, $quotas[$bucket], $bucketIndex);
            $selected = $selected->concat($chosen);
            $existingIds = [...$existingIds, ...$chosen->pluck('id')->all()];
        }

        if ($selected->count() < $count) {
            $selected = $selected->concat(
                $this->candidates(null, $existingIds, $seed.'|backfill')->take($count - $selected->count()),
            );
        }

        DB::transaction(function () use ($selected, $dataset, $split): void {
            foreach ($selected as $conversation) {
                BenchmarkCase::create([
                    'conversation_id' => $conversation->id,
                    'dataset_version' => $dataset,
                    'split' => $split,
                    'sampling_bucket' => $conversation->analysis->primary_category,
                    'source_hash' => $this->sourceHash($conversation),
                    'status' => 'pending',
                    'selected_at' => now(),
                ]);
            }
        });

        $cases = BenchmarkCase::query()
            ->where('dataset_version', $dataset)
            ->whereIn('conversation_id', $selected->pluck('id'))
            ->with('conversation:id,external_short_id,external_id')
            ->orderBy('id')
            ->get();

        $this->line(json_encode([
            'dataset' => $dataset,
            'split' => $split,
            'requested' => $count,
            'created' => $cases->count(),
            'cases' => $cases->map(fn (BenchmarkCase $case): array => [
                'id' => $case->id,
                'ticket' => $case->conversation->external_short_id ?: $case->conversation->external_id,
                'bucket' => $case->sampling_bucket,
                'url' => admin_url('benchmark-cases/'.$case->id),
            ])->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $cases->count() === $count ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, int> */
    private function quotas(int $count): array
    {
        $quotas = array_fill_keys(self::BUCKETS, intdiv($count, count(self::BUCKETS)));
        for ($index = 0; $index < $count % count(self::BUCKETS); $index++) {
            $quotas[self::BUCKETS[$index]]++;
        }

        return $quotas;
    }

    /** @return Collection<int, Conversation> */
    private function candidates(?string $bucket, array $excludedIds, string $seed): Collection
    {
        return Conversation::query()
            ->with('analysis')
            ->whereIn('silo', ['tickets', 'archive'])
            ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
            ->whereHas('analysis', function ($query) use ($bucket): void {
                $query->where('reply_candidate_count', '>', 0);
                if ($bucket !== null) {
                    $query->where('primary_category', $bucket);
                }
            })
            ->get(['id', 'site_key', 'external_id', 'external_short_id', 'updated_at_external'])
            ->sortBy(fn (Conversation $conversation): string => hash('sha256', $seed.'|'.$conversation->id))
            ->values();
    }

    /** @param Collection<int, Conversation> $candidates
     * @return Collection<int, Conversation>
     */
    private function takeWithSiteDiversity(Collection $candidates, int $count, int $rotation): Collection
    {
        if ($count === 0) {
            return collect();
        }

        $sites = ['dressafford.com', 'ellenbridals.com', null];
        $sites = [...array_slice($sites, $rotation % count($sites)), ...array_slice($sites, 0, $rotation % count($sites))];
        $chosen = collect();
        foreach ($sites as $site) {
            if ($chosen->count() >= $count) {
                break;
            }
            $candidate = $candidates->first(fn (Conversation $conversation): bool => $conversation->site_key === $site && ! $chosen->contains('id', $conversation->id));
            if ($candidate !== null) {
                $chosen->push($candidate);
            }
        }

        return $chosen->concat($candidates->whereNotIn('id', $chosen->pluck('id'))->take($count - $chosen->count()))->values();
    }

    private function sourceHash(Conversation $conversation): string
    {
        $events = $conversation->events()
            ->where('event_type', 'message')
            ->whereIn('author_type', ['client', 'agent'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'content_hash', 'is_private', 'occurred_at'])
            ->map->only(['id', 'content_hash', 'is_private', 'occurred_at'])
            ->all();

        return hash('sha256', json_encode([
            'conversation_id' => $conversation->id,
            'updated_at_external' => optional($conversation->updated_at_external)->toIso8601String(),
            'events' => $events,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
