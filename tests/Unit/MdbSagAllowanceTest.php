<?php

namespace Tests\Unit;

use App\Services\Mdb\SagAllowance;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MdbSagAllowanceTest extends TestCase
{
    public function test_sag_is_optional_and_explicit_zero_is_distinct_from_unset(): void
    {
        foreach ([null, ''] as $unset) {
            $result = SagAllowance::apply(30, $unset);
            self::assertSame(30.0, $result['length_m']);
            self::assertNull($result['sag_allowance_percent']);
        }
        $zero = SagAllowance::apply(30, '0');
        self::assertSame(0.0, $zero['sag_allowance_percent']);
        self::assertSame(30.0, $zero['length_m']);
    }

    public function test_percentage_is_applied_once_to_the_base_span_and_unknown_length_stays_unknown(): void
    {
        $span = hypot(30, 10);
        $result = SagAllowance::apply($span, 2.5);
        self::assertEqualsWithDelta($span * 1.025, $result['length_m'], 1e-10);
        self::assertEqualsWithDelta($span * 0.025, $result['sag_addition_m'], 1e-10);
        self::assertSame($span, $result['span_length_m']);
        self::assertNull(SagAllowance::apply(null, 2.5)['length_m']);
        self::assertNull(SagAllowance::apply(null, 2.5)['sag_addition_m']);
    }

    public function test_invalid_percentages_are_rejected(): void
    {
        foreach ([-1, 100.1, 'invalid', INF, NAN] as $value) {
            try {
                SagAllowance::apply(30, $value);
                self::fail('Invalid sag allowance must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('Sag allowance', $exception->getMessage());
            }
        }
    }
}
