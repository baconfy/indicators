<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Indicators;

use Baconfy\Indicators\Contracts\Indicator;
use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final readonly class Atr implements Indicator
{
    public function __construct(public int $period = 14)
    {
        if ($period < 1) {
            throw new InvalidParameterException(
                sprintf('%s requires a period >= 1, %d given.', self::class, $period),
            );
        }
    }

    /**
     * @param  list<Candle>  $candles
     * @return list<BigDecimal|null>
     * @throws MathException
     */
    public function compute(array $candles): array
    {
        $series = [];
        $trueRangeSum = BigDecimal::zero();
        $average = null;
        $previousClose = null;

        foreach ($candles as $index => $candle) {
            // A true range needs a previous close, so the whole series is shifted
            // one bar: the first value lands at index period, not period - 1.
            if ($previousClose === null) {
                $previousClose = $candle->close;
                $series[] = null;

                continue;
            }

            $trueRange = $this->trueRange($candle, $previousClose);
            $previousClose = $candle->close;

            // Each true range is consumed the moment it is produced — the seed
            // needs only their running sum, never the ranges themselves (D10).
            if ($index < $this->period) {
                $trueRangeSum = $trueRangeSum->plus($trueRange);
                $series[] = null;

                continue;
            }

            $average = $average === null
                // Seed: the simple mean of the true ranges at indices 1..period.
                ? Decimal::divide($trueRangeSum->plus($trueRange), $this->period)
                // Wilder smoothing. The trailing division is the policy's, which
                // also holds the state at SCALE — no separate normalization.
                : Decimal::divide(
                    $average->multipliedBy($this->period - 1)->plus($trueRange),
                    $this->period,
                );

            $series[] = $average;
        }

        return $series;
    }

    /**
     * The only indicator reading three candle fields — the uniform contract (D1)
     * is what lets the consumer stay blind to that difference.
     *
     * @throws MathException
     */
    private function trueRange(Candle $candle, BigDecimal $previousClose): BigDecimal
    {
        return BigDecimal::max(
            $candle->high->minus($candle->low),
            $candle->high->minus($previousClose)->abs(),
            $candle->low->minus($previousClose)->abs(),
        );
    }
}
