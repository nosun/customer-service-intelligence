<?php

namespace Tests\Unit;

use App\Services\ProductQuestions\DressLengthChart;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DressLengthChartTest extends TestCase
{
    /** @return iterable<string, array{string, string, int, string}> */
    public static function chartRows(): iterable
    {
        yield 'short mini size 2' => ['Short/Mini', '2', 82, 'hollow_to_hem'];
        yield 'knee size 16W' => ['Knee Length', '16W', 101, 'hollow_to_hem'];
        yield 'high low size 26W' => ['High-Low(Asymetrical)', '26W', 109, 'hollow_to_hem_front'];
        yield 'tea size 14' => ['Tea length', '14', 122, 'hollow_to_hem'];
        yield 'ankle size 10' => ['Ankle Length', '10', 137, 'hollow_to_hem'];
    }

    #[DataProvider('chartRows')]
    public function test_it_resolves_normalized_csv_rows(
        string $hemline,
        string $size,
        int $centimeters,
        string $measurement,
    ): void {
        $result = app(DressLengthChart::class)->lookup($hemline, $size);

        $this->assertSame($centimeters, $result['value_cm']);
        $this->assertSame($measurement, $result['measurement']);
        $this->assertSame('shoulder_to_hem', $result['not_equivalent_to']);
    }

    public function test_every_source_size_has_one_value_for_each_hemline(): void
    {
        $chart = app(DressLengthChart::class)->all();

        $this->assertCount(14, $chart['sizes']);
        $this->assertCount(5, $chart['hemlines']);
        foreach ($chart['hemlines'] as $hemline) {
            $this->assertSame($chart['sizes'], array_map('strval', array_keys($hemline['values_cm'])));
        }
    }
}
