<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Indicators;

use Baconfy\Indicators\Contracts\MultiIndicator;
use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Math\Decimal;
use Baconfy\Indicators\Math\Series;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final readonly class Adx implements MultiIndicator
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
     * @return array<string, list<BigDecimal|null>>
     *
     * @throws MathException
     */
    public function compute(array $candles): array
    {
        [$plusMovement, $minusMovement, $trueRange] = $this->directionalMovement($candles);

        $smoothedPlus = Series::rma($plusMovement, $this->period);
        $smoothedMinus = Series::rma($minusMovement, $this->period);
        $smoothedRange = Series::rma($trueRange, $this->period);

        $plusDi = [];
        $minusDi = [];
        $directionalIndex = [];

        foreach ($smoothedRange as $index => $range) {
            if ($range === null || $range->isZero()) {
                $plusDi[] = null;
                $minusDi[] = null;
                $directionalIndex[] = null;

                continue;
            }

            $plus = Decimal::divide($smoothedPlus[$index]->multipliedBy(100), $range);
            $minus = Decimal::divide($smoothedMinus[$index]->multipliedBy(100), $range);

            $plusDi[] = $plus;
            $minusDi[] = $minus;

            $total = $plus->plus($minus);

            $directionalIndex[] = $total->isZero()
                ? Decimal::round(BigDecimal::zero())
                : Decimal::divide($plus->minus($minus)->abs()->multipliedBy(100), $total);
        }

        return [
            'adx' => $this->averageDirectionalIndex($directionalIndex),
            'plus_di' => $plusDi,
            'minus_di' => $minusDi,
        ];
    }

    /**
     * The three raw series in a single pass: bar 0 has no predecessor, so both
     * movements are defined as zero and the true range is the bar's own span.
     *
     * @param  list<Candle>  $candles
     * @return array{list<BigDecimal>, list<BigDecimal>, list<BigDecimal>}
     *
     * @throws MathException
     */
    private function directionalMovement(array $candles): array
    {
        $plusMovement = [];
        $minusMovement = [];
        $trueRange = [];

        foreach ($candles as $index => $candle) {
            if ($index === 0) {
                $plusMovement[] = BigDecimal::zero();
                $minusMovement[] = BigDecimal::zero();
                $trueRange[] = $candle->high->minus($candle->low);

                continue;
            }

            $previous = $candles[$index - 1];

            $up = $candle->high->minus($previous->high);
            $down = $previous->low->minus($candle->low);

            $plusMovement[] = $up->isGreaterThan($down) && $up->isPositive() ? $up : BigDecimal::zero();
            $minusMovement[] = $down->isGreaterThan($up) && $down->isPositive() ? $down : BigDecimal::zero();

            $trueRange[] = BigDecimal::max(
                $candle->high->minus($candle->low),
                $candle->high->minus($previous->close)->abs(),
                $candle->low->minus($previous->close)->abs(),
            );
        }

        return [$plusMovement, $minusMovement, $trueRange];
    }

    /**
     * The second smoothing runs over the contiguous valid slice of the dx — its
     * own warm-up starts where the dx starts, not at index 0 — and is then
     * re-anchored to the absolute indices (first value at 2 * period - 2).
     *
     * For any period >= 2 the slice runs to the end by construction: once a
     * positive true range has entered the RMA, (prev*(period - 1) + tr) / period
     * can never return to zero, so the dx nulls can only be a leading prefix. At
     * period 1 the recurrence degenerates to the identity (prev is multiplied by
     * zero), so a flat bar sitting exactly on the previous close does send the
     * smoothed range back to zero — hence "contiguous" is taken literally rather
     * than assumed, and the run is bounded instead of sliced to the end.
     *
     * @param  list<BigDecimal|null>  $directionalIndex
     * @return list<BigDecimal|null>
     *
     * @throws MathException
     */
    private function averageDirectionalIndex(array $directionalIndex): array
    {
        $series = array_fill(0, count($directionalIndex), null);

        $start = null;

        foreach ($directionalIndex as $index => $value) {
            if ($value !== null) {
                $start = $index;

                break;
            }
        }

        if ($start === null) {
            return $series;
        }

        $length = 0;

        while (($directionalIndex[$start + $length] ?? null) !== null) {
            $length++;
        }

        foreach (Series::rma(array_slice($directionalIndex, $start, $length), $this->period) as $offset => $value) {
            $series[$start + $offset] = $value;
        }

        return $series;
    }
}
