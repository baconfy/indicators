<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Tests\Support;

use Baconfy\Indicators\Data\Candle;
use Brick\Math\BigDecimal;
use DateMalformedStringException;
use DateTimeImmutable;

final class CandleFactory
{
    /**
     * Builds a series where only the close matters — the other fields are
     * filled with the same value so the candle stays coherent.
     *
     * @param  list<string|int|float>  $closes
     * @return list<Candle>
     * @throws DateMalformedStringException
     */
    public static function fromCloses(array $closes): array
    {
        $candles = [];

        foreach (array_values($closes) as $index => $close) {
            $candles[] = new Candle(
                openTime: new DateTimeImmutable(sprintf('2024-01-01T00:00:00+00:00 +%d days', $index)),
                open: BigDecimal::of((string) $close),
                high: BigDecimal::of((string) $close),
                low: BigDecimal::of((string) $close),
                close: BigDecimal::of((string) $close),
                volume: BigDecimal::of('1'),
            );
        }

        return $candles;
    }

    /**
     * Builds a series for the indicators that weigh the close by the volume —
     * both fields matter, so both are given explicitly.
     *
     * @param  list<string|int|float>  $closes
     * @param  list<string|int|float>  $volumes  same length as $closes
     * @return list<Candle>
     * @throws DateMalformedStringException
     */
    public static function fromClosesAndVolumes(array $closes, array $volumes): array
    {
        $volumes = array_values($volumes);
        $candles = [];

        foreach (array_values($closes) as $index => $close) {
            $candles[] = new Candle(
                openTime: new DateTimeImmutable(sprintf('2024-01-01T00:00:00+00:00 +%d days', $index)),
                open: BigDecimal::of((string) $close),
                high: BigDecimal::of((string) $close),
                low: BigDecimal::of((string) $close),
                close: BigDecimal::of((string) $close),
                volume: BigDecimal::of((string) $volumes[$index]),
            );
        }

        return $candles;
    }

    /**
     * Hydrates the candle rows of a fixture file.
     *
     * @param  list<array{openTime: string, open: string, high: string, low: string, close: string, volume: string}>  $rows
     * @return list<Candle>
     * @throws DateMalformedStringException
     */
    public static function fromRows(array $rows): array
    {
        return array_map(
            static fn (array $row): Candle => new Candle(
                openTime: new DateTimeImmutable($row['openTime']),
                open: BigDecimal::of($row['open']),
                high: BigDecimal::of($row['high']),
                low: BigDecimal::of($row['low']),
                close: BigDecimal::of($row['close']),
                volume: BigDecimal::of($row['volume']),
            ),
            $rows,
        );
    }
}
