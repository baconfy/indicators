<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Indicators;

use Baconfy\Indicators\Attributes\AsIndicator;
use Baconfy\Indicators\Contracts\MultiIndicator;
use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Math\Series;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

#[AsIndicator('macd')]
final readonly class Macd implements MultiIndicator
{
    public function __construct(public int $fast = 12, public int $slow = 26, public int $signal = 9)
    {
        foreach (['fast' => $fast, 'slow' => $slow, 'signal' => $signal] as $name => $period) {
            if ($period < 1) {
                throw new InvalidParameterException(
                    sprintf('%s requires a %s >= 1, %d given.', self::class, $name, $period),
                );
            }
        }

        if ($slow <= $fast) {
            throw new InvalidParameterException(
                sprintf(
                    '%s requires a slow period greater than the fast one, fast %d and slow %d given.',
                    self::class,
                    $fast,
                    $slow,
                ),
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
        $closes = array_map(static fn(Candle $candle): BigDecimal => $candle->close, $candles);

        $fastLine = Series::ema($closes, $this->fast);
        $slowLine = Series::ema($closes, $this->slow);

        $macd = [];

        foreach ($closes as $index => $close) {
            $macd[] = $fastLine[$index] === null || $slowLine[$index] === null
                ? null
                : $fastLine[$index]->minus($slowLine[$index]);
        }

        $signal = $this->signalLine($macd);

        $histogram = [];

        foreach ($macd as $index => $value) {
            $histogram[] = $value === null || $signal[$index] === null
                ? null
                : $value->minus($signal[$index]);
        }

        return ['macd' => $macd, 'signal' => $signal, 'histogram' => $histogram];
    }

    /**
     * The signal EMA runs over the contiguous valid slice of the macd line — its
     * own warm-up starts where the macd line starts, not at index 0 — and is then
     * re-anchored to the absolute indices (first value at slow + signal - 2).
     *
     * @param  list<BigDecimal|null>  $macd
     * @return list<BigDecimal|null>
     *
     * @throws MathException
     */
    private function signalLine(array $macd): array
    {
        $signal = array_fill(0, count($macd), null);

        $start = null;

        foreach ($macd as $index => $value) {
            if ($value !== null) {
                $start = $index;

                break;
            }
        }

        if ($start === null) {
            return $signal;
        }

        foreach (Series::ema(array_slice($macd, $start), $this->signal) as $offset => $value) {
            $signal[$start + $offset] = $value;
        }

        return $signal;
    }
}
