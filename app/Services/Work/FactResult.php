<?php

namespace App\Services\Work;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final readonly class FactResult
{
    public const SCHEMA_VERSION = '1.0.0';

    public const SENSITIVITIES = ['public', 'internal', 'private', 'restricted'];

    public function __construct(
        public string $key,
        public mixed $value,
        public string $source,
        public Carbon $capturedAt,
        public string $sensitivity = 'internal',
        public ?Carbon $freshUntil = null,
    ) {
        if (! in_array($sensitivity, self::SENSITIVITIES, true)) {
            throw new InvalidArgumentException('Unsupported fact sensitivity.');
        }
    }

    public function canShareWithAi(): bool
    {
        return in_array($this->sensitivity, ['public', 'internal'], true);
    }

    public function isFresh(?DateTimeInterface $at = null): bool
    {
        return $this->freshUntil === null || $this->freshUntil->gte($at ?? now());
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'key' => $this->key,
            'value' => $this->value,
            'source' => $this->source,
            'captured_at' => $this->capturedAt->toIso8601String(),
            'sensitivity' => $this->sensitivity,
            'fresh_until' => $this->freshUntil?->toIso8601String(),
            'shareable_with_ai' => $this->canShareWithAi(),
        ];
    }
}
