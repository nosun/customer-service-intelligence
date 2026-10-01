<?php

namespace App\Services\HelpDesk;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HelpDeskClient
{
    private readonly string $accountId;

    private readonly string $personalAccessToken;

    private readonly string $baseUrl;

    public function __construct(
        ?string $accountId = null,
        ?string $personalAccessToken = null,
        ?string $baseUrl = null,
    ) {
        $this->accountId = trim($accountId ?? (string) config('services.helpdesk.account_id'));
        $this->personalAccessToken = trim($personalAccessToken ?? (string) config('services.helpdesk.personal_access_token'));
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.helpdesk.base_url'), '/');
    }

    /**
     * @return array{tickets: list<array<string, mixed>>, requests: int, total_results: ?int}
     */
    public function listTickets(
        string $silo = 'tickets',
        string $status = 'open',
        int $limit = 20,
        int $pageSize = 100,
    ): array {
        $tickets = [];
        $requests = 0;
        $totalResults = null;

        foreach ($this->ticketPages($silo, $status, $limit, $pageSize) as $page) {
            array_push($tickets, ...$page['tickets']);
            $requests += $page['requests'];
            $totalResults ??= $page['total_results'];
        }

        return compact('tickets', 'requests') + ['total_results' => $totalResults];
    }

    /**
     * @return Generator<int, array{tickets: list<array<string, mixed>>, requests: int, total_results: ?int}>
     */
    public function ticketPages(
        string $silo = 'tickets',
        string $status = 'open',
        int $limit = 20,
        int $pageSize = 100,
        string $sortBy = 'createdAt',
        ?array $initialCursor = null,
    ): Generator {
        $this->ensureConfigured();

        if (! in_array($sortBy, ['createdAt', 'updatedAt'], true)) {
            throw new RuntimeException('HelpDesk ticket sort field must be createdAt or updatedAt.');
        }

        $limit = max(1, $limit);
        $pageSize = min(100, max(1, $pageSize));
        $count = 0;
        $cursor = $initialCursor;
        $totalResults = null;

        while ($count < $limit) {
            $remaining = $limit - $count;
            $query = [
                'silo' => $silo,
                'status' => $status,
                'pageSize' => min($pageSize, $remaining),
                'sortBy' => $sortBy,
                'order' => 'asc',
            ];

            if ($cursor !== null) {
                $query['next.value'] = $cursor['value'];
                $query['next.ID'] = $cursor['ID'];
            }

            $response = $this->request()->get('/v1/tickets', $query);
            $response->throw();
            $page = $response->json();
            if (! is_array($page) || ! array_is_list($page)) {
                throw new RuntimeException('HelpDesk returned an invalid ticket list.');
            }

            if ($totalResults === null && is_numeric($response->header('X-Total-Results'))) {
                $totalResults = (int) $response->header('X-Total-Results');
            }

            $tickets = [];
            foreach (array_slice($page, 0, $remaining) as $ticket) {
                if (! is_array($ticket)) {
                    throw new RuntimeException('HelpDesk returned an invalid ticket.');
                }
                $tickets[] = $ticket;
            }
            $count += count($tickets);

            yield ['tickets' => $tickets, 'requests' => 1, 'total_results' => $totalResults];

            if ($count >= $limit || count($page) < $query['pageSize']) {
                break;
            }

            $last = $page[array_key_last($page)] ?? [];
            $cursorValue = $last[$sortBy] ?? null;
            $cursorId = $last['ID'] ?? null;
            if (! is_string($cursorValue) || $cursorValue === '' || ! is_string($cursorId) || $cursorId === '') {
                throw new RuntimeException('HelpDesk ticket page is missing the pagination cursor.');
            }
            $cursor = ['value' => $cursorValue, 'ID' => $cursorId];
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withBasicAuth($this->accountId, $this->personalAccessToken)
            ->acceptJson()
            ->withUserAgent('customer-service-intelligence/0.1')
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(3, 500, throw: false);
    }

    private function ensureConfigured(): void
    {
        if ($this->accountId === '' || $this->personalAccessToken === '') {
            throw new RuntimeException(
                'HelpDesk credentials are missing. Configure HELPDESK_ACCOUNT_ID and HELPDESK_PERSONAL_ACCESS_TOKEN.',
            );
        }
    }
}
