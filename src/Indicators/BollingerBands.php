<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Indicators;

use Baconfy\Indicators\Contracts\MultiIndicator;
use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final readonly class BollingerBands implements MultiIndicator
{
    public BigDecimal $multiplier;

    /**
     * @throws MathException
     */
    public function __construct(public int $period = 20, BigDecimal|int|float|string $multiplier = '2.0')
    {
        if ($period < 1) {
            throw new InvalidParameterException(
                sprintf('%s requires a period >= 1, %d given.', self::class, $period),
            );
        }

        // brick/math 0.18 refuses floats outright (they are lossy), so the float
        // leg of the accepted union is rendered first — var_export, not a plain
        // cast, because the cast truncates at the `precision` ini (14 digits).
        $this->multiplier = BigDecimal::of(is_float($multiplier) ? var_export($multiplier, true) : $multiplier);

        if ($this->multiplier->isNegativeOrZero()) {
            throw new InvalidParameterException(
                sprintf('%s requires a multiplier > 0, %s given.', self::class, $this->multiplier),
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
        $basis = (new Sma($this->period))->compute($candles);

        $upper = [];
        $lower = [];

        $sum = BigDecimal::zero();
        $sumSq = BigDecimal::zero();

        foreach ($candles as $index => $candle) {
            $close = $candle->close;

            $sum = $sum->plus($close);
            $sumSq = $sumSq->plus($close->multipliedBy($close));

            if ($index >= $this->period) {
                $leaving = $candles[$index - $this->period]->close;

                $sum = $sum->minus($leaving);
                $sumSq = $sumSq->minus($leaving->multipliedBy($leaving));
            }

            if ($index < $this->period - 1) {
                $upper[] = null;
                $lower[] = null;

                continue;
            }

            // Population variance as (n·sumSq − sum²) / n²: the numerator stays
            // exact over the rolling accumulators, so each window costs ONE policy
            // division and never a rescan (D10).
            $variance = Decimal::divide($sumSq->multipliedBy($this->period)->minus($sum->multipliedBy($sum)), $this->period * $this->period);

            $band = $this->multiplier->multipliedBy(Decimal::sqrt($variance));

            $upper[] = Decimal::round($basis[$index]->plus($band));
            $lower[] = Decimal::round($basis[$index]->minus($band));
        }

        return ['basis' => $basis, 'upper' => $upper, 'lower' => $lower];
    }
}
