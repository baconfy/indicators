<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Indicators;

use Baconfy\Indicators\Contracts\Indicator;
use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Math\Decimal;
use Brick\Math\BigDecimal;

final readonly class Sma implements Indicator
{
    public function __construct(public int $period = 9)
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
     */
    public function compute(array $candles): array
    {
        $series = [];

        // Rolling window sum: the close entering the window is added, the one
        // leaving it is subtracted. BigDecimal plus/minus are exact, so this is
        // mathematically identical to re-summing the window at every index.
        $sum = BigDecimal::zero();

        foreach ($candles as $index => $candle) {
            $sum = $sum->plus($candle->close);

            if ($index >= $this->period) {
                $sum = $sum->minus($candles[$index - $this->period]->close);
            }

            if ($index < $this->period - 1) {
                $series[] = null;

                continue;
            }

            $series[] = Decimal::divide($sum, $this->period);
        }

        return $series;
    }
}
