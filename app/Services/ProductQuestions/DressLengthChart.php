<?php

namespace App\Services\ProductQuestions;

use RuntimeException;

class DressLengthChart
{
    /** @var array<string, mixed>|null */
    private ?array $chart = null;

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->chart !== null) {
            return $this->chart;
        }

        $contents = file_get_contents(resource_path('data/dress-hollow-to-hem.json'));
        if ($contents === false) {
            throw new RuntimeException('Dress length chart could not be read.');
        }

        return $this->chart = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param string|list<string>|null $hemlines @return array<string, mixed>|null */
    public function lookup(string|array|null $hemlines, string|int|null $size): ?array
    {
        $size = strtoupper(trim((string) $size));
        if ($size === '') {
            return null;
        }

        foreach ((array) $hemlines as $hemline) {
            foreach ($this->all()['hemlines'] as $key => $definition) {
                $aliases = [$definition['label'], ...$definition['aliases']];
                if (! collect($aliases)->contains(fn (string $alias): bool => $this->normalize($alias) === $this->normalize((string) $hemline))) {
                    continue;
                }
                $centimeters = $definition['values_cm'][$size] ?? null;
                if (! is_numeric($centimeters)) {
                    return null;
                }

                return [
                    'hemline_key' => $key,
                    'hemline' => $definition['label'],
                    'size' => $size,
                    'measurement' => $definition['measurement'],
                    'value_cm' => (int) $centimeters,
                    'value_in' => round(((float) $centimeters) / 2.54, 1),
                    'measurement_definition' => $this->all()['definition'][$definition['measurement']],
                    'not_equivalent_to' => $this->all()['definition']['not_equivalent_to'],
                ];
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', trim($value)));
    }
}
