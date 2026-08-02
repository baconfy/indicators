<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Indicators;

use Baconfy\Indicators\Contracts\Indicator;
use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * On-Balance Volume — the mirror case of the warm-up law: defined from bar 0,
 * it is the only indicator whose series holds no null at all. The return type
 * stays list<BigDecimal|null> per the contract; it simply never emits the null.
 */
final readonly class Obv implements Indicator
{
    /**
     * @param  list<Candle>  $candles
     * @return list<BigDecimal|null>
     *
     * @throws MathException
     */
    public function compute(array $candles): array
    {
        $series = [];
        $previous = Decimal::round(BigDecimal::zero());

        foreach ($candles as $index => $candle) {
            if ($index > 0) {
                $direction = $candle->close->compareTo($candles[$index - 1]->close);

                if ($direction > 0) {
                    $previous = Decimal::round($previous->plus($candle->volume));
                } elseif ($direction < 0) {
                    $previous = Decimal::round($previous->minus($candle->volume));
                }
            }

            $series[] = $previous;
        }

        return $series;
    }
}
