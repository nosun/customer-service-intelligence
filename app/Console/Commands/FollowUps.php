<?php

namespace App\Console\Commands;

use App\Models\FollowUp;
use App\Services\FollowUps\FollowUpManager;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\Console\Output\OutputInterface;

class FollowUps extends Command
{
    protected $signature = 'follow-ups {action=list : list, show, create, update} {id?}
        {--data= : JSON object of fields to create or update}
        {--input= : Read the JSON object from a local file}
        {--expected-version= : Required current version for update}
        {--actor=ai:codex : Attribution recorded in the log}
        {--view=open : open, due, today, urgent, unscheduled, closed, all}
        {--search= : Search title, reference, owner or Ticket number}
        {--conversation-id= : Filter by local Ticket conversation ID}
        {--page=1 : Page number}
        {--limit=30 : Records per page, up to 100}';

    protected $description = 'Read and maintain the local follow-up ledger; outputs JSON and never sends messages or modifies orders';

    public function handle(FollowUpManager $manager): int
    {
        try {
            $args = Validator::make([
                'action' => $this->argument('action'), 'id' => $this->argument('id'),
                'version' => $this->option('expected-version'), 'actor' => $this->option('actor'),
                'page' => $this->option('page'), 'limit' => $this->option('limit'),
            ], [
                'action' => ['required', Rule::in(['list', 'show', 'create', 'update'])],
                'id' => ['required_if:action,show,update', 'nullable', 'integer', 'min:1'],
                'version' => ['required_if:action,update', 'nullable', 'integer', 'min:1'],
                'actor' => ['required', 'string', 'max:150'],
                'page' => ['required', 'integer', 'min:1'], 'limit' => ['required', 'integer', 'between:1,100'],
            ])->validate();
            if ($args['action'] === 'list') {
                $items = $manager->query([
                    'view' => $this->option('view'), 'search' => $this->option('search'),
                    'conversation_id' => $this->option('conversation-id'),
                ])->paginate((int) $args['limit'], ['*'], 'page', (int) $args['page']);
                $this->outputJson(['timezone' => FollowUp::TIMEZONE, 'data' => $items->items(), 'total' => $items->total(), 'page' => $items->currentPage(), 'last_page' => $items->lastPage()]);

                return self::SUCCESS;
            }
            $item = match ($args['action']) {
                'show' => FollowUp::findOrFail((int) $args['id']),
                'create' => $manager->create($this->inputData(), $args['actor']),
                'update' => $manager->update((int) $args['id'], $this->inputData(), (int) $args['version'], $args['actor']),
            };
            $item->load('conversation:id,external_short_id,subject');
            $entries = $item->entries()->latest('id')->paginate((int) $args['limit'], ['*'], 'page', (int) $args['page']);
            $this->outputJson(['timezone' => FollowUp::TIMEZONE, 'data' => $item, 'entries' => $entries->items(), 'entries_total' => $entries->total(), 'entries_page' => $entries->currentPage(), 'entries_last_page' => $entries->lastPage()]);

            return self::SUCCESS;
        } catch (ValidationException $e) {
            $this->outputJson(['error' => 'validation_failed', 'errors' => $e->errors()]);
        } catch (ModelNotFoundException) {
            $this->outputJson(['error' => 'not_found']);
        } catch (JsonException $e) {
            $this->outputJson(['error' => 'invalid_json', 'message' => $e->getMessage()]);
        }

        return self::FAILURE;
    }

    private function inputData(): array
    {
        if ($this->option('input') && $this->option('data')) {
            throw ValidationException::withMessages(['data' => '--input 和 --data 只能选择一个。']);
        }
        $json = $this->option('data');
        if ($path = $this->option('input')) {
            if (! is_file($path) || ! is_readable($path)) {
                throw ValidationException::withMessages(['input' => '无法读取 JSON 文件。']);
            }
            $json = file_get_contents($path);
        }
        $value = json_decode((string) $json, false, 512, JSON_THROW_ON_ERROR);
        if (! is_object($value)) {
            throw ValidationException::withMessages(['data' => '请提供 JSON 对象。']);
        }

        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function outputJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    }
}
