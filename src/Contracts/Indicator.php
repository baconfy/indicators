<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Contracts;

use Baconfy\Indicators\Data\Candle;
use Brick\Math\BigDecimal;

interface Indicator
{
    /**
     * @param list<Candle> $candles ordered oldest to newest
     * @return list<BigDecimal|null> same length as $candles, index-aligned;
     *                               null where the indicator does not exist yet (warm-up)
     */
    public function compute(array $candles): array;
}
