<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Contracts;

use Baconfy\Indicators\Data\Candle;
use Brick\Math\BigDecimal;

/**
 * A sibling of Indicator, deliberately not a parent nor a child of it: the
 * single-series contract stays pure — the simple case must not pay for the
 * rich one.
 */
interface MultiIndicator
{
    /**
     * @param list<Candle> $candles ordered oldest to newest
     * @return array<string, list<BigDecimal|null>> named series; EVERY series has
     *         the same length as $candles, index-aligned, null where the series
     *         is undefined (warm-up or mathematically undefined mid-series).
     *         The series names are public API, governed by semver.
     */
    public function compute(array $candles): array;
}
