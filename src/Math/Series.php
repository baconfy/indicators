<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Math;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * The recurrences shared by more than one indicator, over plain value series.
 *
 * An indicator reads the candle fields it needs and hands the resulting values
 * here; the math itself is never duplicated (D6: MACD composes the EMA, it does
 * not reimplement it).
 *
 * @internal
 */
final class Series
{
    /**
     * Exponential moving average: multiplier k = 2/(period+1) through the policy,
     * seeded at index period - 1 with the simple mean of the first period values,
     * then ema[i] = (value[i] - ema[i-1]) * k + ema[i-1] — one O(n) pass, the
     * recurrent state re-quantized to the policy scale at every step (D5).
     *
     * @param  list<BigDecimal>  $values
     * @return list<BigDecimal|null> same length as $values, null during warm-up
     *
     * @throws MathException
     */
    public static function ema(array $values, int $period): array
    {
        $series = [];
        $multiplier = Decimal::divide(BigDecimal::of(2), $period + 1);
        $seedSum = BigDecimal::zero();
        $previous = null;

        foreach ($values as $index => $value) {
            if ($index < $period - 1) {
                $seedSum = $seedSum->plus($value);
                $series[] = null;

                continue;
            }

            if ($previous === null) {
                $previous = Decimal::divide($seedSum->plus($value), $period);
                $series[] = $previous;

                continue;
            }

            $previous = Decimal::round($value->minus($previous)->multipliedBy($multiplier)->plus($previous));

            $series[] = $previous;
        }

        return $series;
    }
}
