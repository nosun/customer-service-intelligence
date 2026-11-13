<?php

namespace App\Services\ConsoleNew;

class ConsoleNewConnections
{
    public function definitions(): array
    {
        return [
            ...config('console.connections', []),
            'seawave' => config('services.console_new', []),
        ];
    }

    public function anyConfigured(): bool
    {
        foreach (array_keys($this->definitions()) as $key) {
            if ($this->connection($key)->isConfigured()) {
                return true;
            }
        }

        return false;
    }

    public function connection(string $key): ConsoleNewClient
    {
        $definition = $this->definitions()[$key] ?? null;
        if (! is_array($definition)) {
            throw new ConsoleNewApiException('未知 Console 终端：'.$key, errorCode: 'UNKNOWN_CONNECTION');
        }

        return new ConsoleNewClient(
            (string) ($definition['base_url'] ?? ''),
            (string) ($definition['token'] ?? ''),
            (int) ($definition['timeout'] ?? 15),
            $key,
        );
    }

    public function forContext(?string $siteKey, ?string $orderNumber = null): ConsoleNewClient
    {
        $key = $this->contextConnectionKey($siteKey, $orderNumber);
        if ($key === null) {
            $configured = array_keys(array_filter($this->definitions(), fn ($d) => filled($d['base_url'] ?? null) && filled($d['token'] ?? null)));
            if (count($configured) !== 1) {
                throw new ConsoleNewApiException('无法唯一确定 Console 终端，请先明确站点或订单号。', errorCode: 'AMBIGUOUS_CONNECTION');
            }
            $key = $configured[0];
        }
        $client = $this->connection($key);
        if (! $client->isConfigured()) {
            throw new ConsoleNewApiException('Console 终端 '.$key.' 未配置独立的 API 地址和 Token。', errorCode: 'NOT_CONFIGURED');
        }

        return $client;
    }

    /** Resolve known routing evidence without guessing from the configured credentials. */
    public function contextConnectionKey(?string $siteKey, ?string $orderNumber = null): ?string
    {
        $site = self::normalizeSite((string) $siteKey);
        $siteConnection = config('console.sites', [])[$site] ?? null;
        preg_match('/\A([a-z]+)\d{8}-\d+\z/i', trim((string) $orderNumber), $match);
        $orderConnection = config('console.order_prefixes', [])[strtolower($match[1] ?? '')] ?? null;
        if ($siteConnection !== null && $orderConnection !== null && $siteConnection !== $orderConnection) {
            throw new ConsoleNewApiException('Ticket 站点与订单号指向不同 Console 终端，请核实订单。', errorCode: 'CONNECTION_MISMATCH');
        }

        return $siteConnection ?? $orderConnection;
    }

    /** A failed routing decision is still a stable input; it must not use a stale successful snapshot. */
    public function contextFingerprint(?string $siteKey, ?string $orderNumber = null): string
    {
        try {
            return $this->forContext($siteKey, $orderNumber)->source()['fingerprint'];
        } catch (ConsoleNewApiException $e) {
            return hash('sha256', json_encode([$e->errorCode, $siteKey, $orderNumber]));
        }
    }

    public static function normalizeSite(string $site): string
    {
        $site = strtolower(trim($site));
        if (str_contains($site, '://')) {
            $site = (string) parse_url($site, PHP_URL_HOST);
        }

        return preg_replace('/^www\./', '', rtrim($site, '/.'));
    }
}
