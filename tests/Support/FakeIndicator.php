<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Tests\Support;

use Baconfy\Indicators\Contracts\Indicator;
use Baconfy\Indicators\Data\Candle;
use Brick\Math\BigDecimal;

/**
 * A trivial Indicator used to prove the manager resolves registered classes —
 * it computes nothing, it only honours the contract.
 */
final readonly class FakeIndicator implements Indicator
{
    public function __construct(public int $period = 3) {}

    /**
     * @param  list<Candle>  $candles
     * @return list<BigDecimal|null>
     */
    public function compute(array $candles): array
    {
        return array_map(static fn (): ?BigDecimal => null, $candles);
    }
}
