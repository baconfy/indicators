<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Indicators;

use Baconfy\Indicators\Contracts\Indicator;
use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final readonly class Rsi implements Indicator
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
        $gainSum = BigDecimal::zero();
        $lossSum = BigDecimal::zero();
        $averageGain = null;
        $averageLoss = null;
        $previousClose = null;

        foreach ($candles as $index => $candle) {
            if ($previousClose === null) {
                $previousClose = $candle->close;
                $series[] = null;

                continue;
            }

            $change = $candle->close->minus($previousClose);
            $previousClose = $candle->close;

            $gain = $change->isPositive() ? $change : BigDecimal::zero();
            $loss = $change->isNegative() ? $change->negated() : BigDecimal::zero();

            if ($index < $this->period) {
                $gainSum = $gainSum->plus($gain);
                $lossSum = $lossSum->plus($loss);
                $series[] = null;

                continue;
            }

            if ($averageGain === null) {
                $averageGain = Decimal::divide($gainSum->plus($gain), $this->period);
                $averageLoss = Decimal::divide($lossSum->plus($loss), $this->period);
            } else {
                $averageGain = Decimal::divide($averageGain->multipliedBy($this->period - 1)->plus($gain), $this->period);
                $averageLoss = Decimal::divide($averageLoss->multipliedBy($this->period - 1)->plus($loss), $this->period);
            }

            $series[] = $this->relativeStrengthIndex($averageGain, $averageLoss);
        }

        return $series;
    }

    /**
     * The edge cases are defined, not accidental (D6) — and still emitted at the
     * policy scale, so the whole series is uniformly scale SCALE.
     *
     * @throws MathException
     */
    private function relativeStrengthIndex(BigDecimal $averageGain, BigDecimal $averageLoss): BigDecimal
    {
        if ($averageLoss->isZero()) {
            return Decimal::round(BigDecimal::of(100));
        }

        if ($averageGain->isZero()) {
            return Decimal::round(BigDecimal::of(0));
        }

        $relativeStrength = Decimal::divide($averageGain, $averageLoss);

        return Decimal::round(
            BigDecimal::of(100)->minus(Decimal::divide(BigDecimal::of(100), $relativeStrength->plus(1))),
        );
    }
}
