<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Indicators;

use Baconfy\Indicators\Contracts\Indicator;
use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final readonly class Rma implements Indicator
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
        $seedSum = BigDecimal::zero();
        $previous = null;

        foreach ($candles as $index => $candle) {
            if ($index < $this->period - 1) {
                $seedSum = $seedSum->plus($candle->close);
                $series[] = null;

                continue;
            }

            if ($previous === null) {
                $previous = Decimal::divide($seedSum->plus($candle->close), $this->period);
                $series[] = $previous;

                continue;
            }

            // The division is the policy's, and it is also what bounds the scale:
            // the recurrence never needs an explicit normalization (D5).
            $previous = Decimal::divide(
                $previous->multipliedBy($this->period - 1)->plus($candle->close),
                $this->period,
            );

            $series[] = $previous;
        }

        return $series;
    }
}
