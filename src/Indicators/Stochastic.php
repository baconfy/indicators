<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Indicators;

use Baconfy\Indicators\Contracts\MultiIndicator;
use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final readonly class Stochastic implements MultiIndicator
{
    public function __construct(public int $kPeriod = 14, public int $kSmooth = 3, public int $dSmooth = 3)
    {
        foreach (['kPeriod' => $kPeriod, 'kSmooth' => $kSmooth, 'dSmooth' => $dSmooth] as $name => $period) {
            if ($period < 1) {
                throw new InvalidParameterException(
                    sprintf('%s requires a %s >= 1, %d given.', self::class, $name, $period),
                );
            }
        }
    }

    /**
     * @param  list<Candle>  $candles
     * @return array<string, list<BigDecimal|null>>
     *
     * @throws MathException
     */
    public function compute(array $candles): array
    {
        $rawK = $this->rawK($candles);

        $k = $this->kSmooth === 1 ? $rawK : $this->smooth($rawK, $this->kSmooth);
        $d = $this->smooth($k, $this->dSmooth);

        return ['k' => $k, 'd' => $d];
    }

    /**
     * rawK = 100·(close − LL) / (HH − LL) over the last kPeriod bars.
     *
     * The rolling extremes come from two monotonic deques of indices (front = the
     * current extreme): a bar entering the window evicts every bar it dominates
     * from the back, and the front is dropped once it falls out of the window.
     * Each index is pushed and popped at most once, so the whole series costs one
     * amortized O(1) update per bar and the window is never rescanned (D10).
     *
     * @param  list<Candle>  $candles
     * @return list<BigDecimal|null>
     *
     * @throws MathException
     */
    private function rawK(array $candles): array
    {
        $series = [];

        // Plain arrays with head pointers: array_shift would be O(n) per call and
        // would give back exactly the complexity the deque exists to avoid.
        $highs = [];
        $highsHead = 0;
        $lows = [];
        $lowsHead = 0;

        foreach ($candles as $index => $candle) {
            while ($highsHead < count($highs) && $candles[$highs[count($highs) - 1]]->high->isLessThanOrEqualTo($candle->high)) {
                array_pop($highs);
            }

            $highs[] = $index;

            while ($lowsHead < count($lows) && $candles[$lows[count($lows) - 1]]->low->isGreaterThanOrEqualTo($candle->low)) {
                array_pop($lows);
            }

            $lows[] = $index;

            if ($highs[$highsHead] <= $index - $this->kPeriod) {
                $highsHead++;
            }

            if ($lows[$lowsHead] <= $index - $this->kPeriod) {
                $lowsHead++;
            }

            if ($index < $this->kPeriod - 1) {
                $series[] = null;

                continue;
            }

            $highest = $candles[$highs[$highsHead]]->high;
            $lowest = $candles[$lows[$lowsHead]]->low;
            $range = $highest->minus($lowest);

            // A window that went nowhere has no position within its own range:
            // undefined, not zero — and mid-series, so the next bar can define it
            // again (D3).
            $series[] = $range->isZero()
                ? null
                : Decimal::divide($candle->close->minus($lowest)->multipliedBy(100), $range);
        }

        return $series;
    }

    /**
     * The simple moving average used by both smoothings, over a series that may
     * hold nulls: a window containing one is undefined as a whole (D6), so the
     * rolling sum is carried alongside a count of the nulls currently in it —
     * still one O(n) pass, still no rescan.
     *
     * @param  list<BigDecimal|null>  $values
     * @return list<BigDecimal|null>
     *
     * @throws MathException
     */
    private function smooth(array $values, int $period): array
    {
        $series = [];
        $sum = BigDecimal::zero();
        $nulls = 0;

        foreach ($values as $index => $value) {
            if ($value === null) {
                $nulls++;
            } else {
                $sum = $sum->plus($value);
            }

            if ($index >= $period) {
                $leaving = $values[$index - $period];

                if ($leaving === null) {
                    $nulls--;
                } else {
                    $sum = $sum->minus($leaving);
                }
            }

            if ($index < $period - 1) {
                $series[] = null;

                continue;
            }

            $series[] = $nulls > 0 ? null : Decimal::divide($sum, $period);
        }

        return $series;
    }
}
