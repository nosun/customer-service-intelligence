<?php

namespace App\Services\Knowledge;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WeKnoraClient
{
    public function baseFor(?string $site): string
    {
        $base = config('weknora.knowledge_bases')[$site ?: 'global'] ?? null;
        if (! is_string($base) || trim($base) === '') {
            throw new RuntimeException('No explicitly allowed WeKnora knowledge base for this site.');
        }

        return $base;
    }

    public function save(string $base, ?string $id, string $title, string $content): array
    {
        $this->assertBase($base);
        $request = $this->request(true);
        $payload = ['title' => $title, 'content' => $content, 'status' => 'publish', 'channel' => 'api'];
        $response = $id === null
            ? $request->post('api/v1/knowledge-bases/'.rawurlencode($base).'/knowledge/manual', $payload)
            : $request->put('api/v1/knowledge/manual/'.rawurlencode($id), $payload);

        return $this->data($response->throw()->json());
    }

    public function get(string $id): array
    {
        return $this->data($this->request(false)->get('api/v1/knowledge/'.rawurlencode($id))->throw()->json());
    }

    public function search(string $base, string $query, array $ids = []): array
    {
        $this->assertBase($base);

        return $this->data($this->request(false)->post('api/v1/knowledge-search', [
            'query' => $query, 'knowledge_base_ids' => [$base], 'knowledge_ids' => array_values($ids),
        ])->throw()->json());
    }

    private function assertBase(string $base): void
    {
        if (! in_array($base, config('weknora.knowledge_bases', []), true)) {
            throw new RuntimeException('WeKnora target is not allowlisted.');
        }
    }

    private function data(mixed $response): array
    {
        if (! is_array($response) || ($response['success'] ?? false) !== true || ! is_array($response['data'] ?? null)) {
            throw new RuntimeException('Unexpected WeKnora response.');
        }

        return $response['data'];
    }

    private function request(bool $write): PendingRequest
    {
        $key = trim((string) config($write ? 'weknora.write_key' : 'weknora.read_key'));
        $url = rtrim((string) config('weknora.base_url'), '/');
        if ($key === '' || $url === '') {
            throw new RuntimeException('Configure WeKnora endpoint and scoped credentials.');
        }

        return Http::baseUrl($url)->withHeaders(['X-API-Key' => $key])->acceptJson()->asJson()->connectTimeout(2)->timeout(8);
    }
}
