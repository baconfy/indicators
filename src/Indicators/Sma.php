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

        foreach ($candles as $index => $candle) {
            if ($index < $this->period - 1) {
                $series[] = null;

                continue;
            }

            $sum = BigDecimal::zero();

            for ($offset = $index - $this->period + 1; $offset <= $index; $offset++) {
                $sum = $sum->plus($candles[$offset]->close);
            }

            $series[] = Decimal::divide($sum, $this->period);
        }

        return $series;
    }
}
