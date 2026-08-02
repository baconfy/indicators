<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Indicators;

use Baconfy\Indicators\Contracts\Indicator;
use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final readonly class Vwma implements Indicator
{
    public function __construct(public int $period = 20)
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
     *
     * @throws MathException
     */
    public function compute(array $candles): array
    {
        $series = [];
        $weightedSum = BigDecimal::zero();
        $volumeSum = BigDecimal::zero();

        foreach ($candles as $index => $candle) {
            $weightedSum = $weightedSum->plus($candle->close->multipliedBy($candle->volume));
            $volumeSum = $volumeSum->plus($candle->volume);

            if ($index >= $this->period) {
                $leaving = $candles[$index - $this->period];

                $weightedSum = $weightedSum->minus($leaving->close->multipliedBy($leaving->volume));
                $volumeSum = $volumeSum->minus($leaving->volume);
            }

            if ($index < $this->period - 1) {
                $series[] = null;

                continue;
            }

            // A window that traded nothing has no volume-weighted price: undefined,
            // not zero — and mid-series, so it can be defined again right after.
            $series[] = $volumeSum->isZero() ? null : Decimal::divide($weightedSum, $volumeSum);
        }

        return $series;
    }
}
