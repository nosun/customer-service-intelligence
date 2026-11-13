<?php

namespace App\Services\FollowUps;

use App\Models\FollowUp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FollowUpManager
{
    public function query(array $filters = []): Builder
    {
        $data = Validator::make($filters, [
            'view' => ['sometimes', Rule::in(array_keys(FollowUp::FILTERS))],
            'search' => ['nullable', 'string', 'max:200'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        $view = $data['view'] ?? 'open';
        $query = FollowUp::query()->with('conversation:id,external_short_id,subject');
        if ($view !== 'all') {
            $query->where('status', $view === 'closed' ? '=' : '!=', 'closed');
        }
        if ($view === 'due') {
            $query->where('next_follow_up_at', '<=', now());
        } elseif ($view === 'today') {
            $query->whereBetween('next_follow_up_at', [now(FollowUp::TIMEZONE)->startOfDay()->utc(), now(FollowUp::TIMEZONE)->endOfDay()->utc()]);
        } elseif ($view === 'urgent') {
            $query->where('urgent', true);
        } elseif ($view === 'unscheduled') {
            $query->whereNull('next_follow_up_at');
        }
        if (! empty($data['conversation_id'])) {
            $query->where('conversation_id', $data['conversation_id']);
        }
        if ($search = trim($data['search'] ?? '')) {
            $query->where(fn ($query) => $query->where('title', 'like', '%'.$search.'%')
                ->orWhere('reference', 'like', '%'.$search.'%')
                ->orWhere('owner', 'like', '%'.$search.'%')
                ->orWhereHas('conversation', fn ($query) => $query->where('external_short_id', 'like', '%'.$search.'%')));
        }

        return $query->orderByRaw("CASE WHEN status = 'closed' THEN 1 ELSE 0 END")
            ->orderByDesc('urgent')->orderByRaw('CASE WHEN next_follow_up_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_follow_up_at')->orderByDesc('id');
    }

    public function create(array $input, string $actor): FollowUp
    {
        $data = $this->validate($input, true);
        $note = $data['note'] ?? null;
        unset($data['note']);
        $data += ['status' => 'open', 'urgent' => false, 'next_follow_up_at' => now(FollowUp::TIMEZONE)->addDay()->setTime(10, 0)->utc()];

        return DB::transaction(function () use ($data, $note, $actor): FollowUp {
            $item = new FollowUp($data);
            $item->latest_note = $note;
            $item->closed_at = $item->status === 'closed' ? now() : null;
            $item->save();
            $item->entries()->create(['actor' => $actor, 'note' => $note, 'changes' => $this->changes([], $this->snapshot($item))]);

            return $item;
        });
    }

    public function update(int $id, array $input, int $version, string $actor): FollowUp
    {
        $data = $this->validate($input, false);
        $note = $data['note'] ?? null;
        unset($data['note']);

        return DB::transaction(function () use ($id, $data, $note, $version, $actor): FollowUp {
            $item = FollowUp::lockForUpdate()->findOrFail($id);
            if ($item->version !== $version) {
                throw ValidationException::withMessages(['version' => '此事项已被其他人或 AI 更新，请刷新后再提交。']);
            }
            $before = $this->snapshot($item);
            $item->fill($data);
            $changes = $this->changes($before, $this->snapshot($item));
            if ($changes === [] && $note === null) {
                throw ValidationException::withMessages(['note' => '请填写进展，或修改状态、时间等信息。']);
            }
            if ($note !== null) {
                $item->latest_note = $note;
            }
            if ($item->isDirty('status')) {
                $item->closed_at = $item->status === 'closed' ? now() : null;
            }
            $item->version++;
            $item->save();
            $item->entries()->create(['actor' => $actor, 'note' => $note, 'changes' => $changes]);

            return $item;
        });
    }

    private function validate(array $input, bool $creating): array
    {
        foreach (['title', 'note', 'reference', 'owner'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
                if ($input[$key] === '') {
                    $input[$key] = null;
                }
            }
        }
        $data = Validator::make($input, [
            'title' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:20000'],
            'status' => ['sometimes', 'required', Rule::in(array_keys(FollowUp::STATUSES))],
            'urgent' => ['sometimes', 'boolean'],
            'reference' => ['nullable', 'string', 'max:1000'],
            'owner' => ['nullable', 'string', 'max:100'],
            'conversation_id' => ['nullable', 'integer', Rule::exists('conversations', 'id')->where('silo', 'tickets')],
            'next_follow_up_at' => ['nullable', 'date'],
        ])->validate();
        if (! empty($data['next_follow_up_at'])) {
            $data['next_follow_up_at'] = Carbon::parse($data['next_follow_up_at'], FollowUp::TIMEZONE)->utc();
        }

        return $data;
    }

    private function snapshot(FollowUp $item): array
    {
        return [
            'title' => $item->title, 'status' => $item->status, 'urgent' => (bool) $item->urgent,
            'reference' => $item->reference, 'owner' => $item->owner, 'conversation_id' => $item->conversation_id === null ? null : (int) $item->conversation_id,
            'next_follow_up_at' => $item->next_follow_up_at?->toIso8601String(),
        ];
    }

    private function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $value) {
            if (! array_key_exists($key, $before) || $before[$key] !== $value) {
                $changes[$key] = ['before' => $before[$key] ?? null, 'after' => $value];
            }
        }

        return $changes;
    }
}
