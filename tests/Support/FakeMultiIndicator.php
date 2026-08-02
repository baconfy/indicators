<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Tests\Support;

use Baconfy\Indicators\Contracts\MultiIndicator;
use Baconfy\Indicators\Data\Candle;
use Brick\Math\BigDecimal;

/**
 * A trivial MultiIndicator used to prove the manager resolves registered classes —
 * it computes nothing, it only honours the contract: named series, every one of
 * them index-aligned to the input.
 */
final readonly class FakeMultiIndicator implements MultiIndicator
{
    public function __construct(public int $period = 3) {}

    /**
     * @param  list<Candle>  $candles
     * @return array<string, list<BigDecimal|null>>
     */
    public function compute(array $candles): array
    {
        $series = array_map(static fn (): ?BigDecimal => null, $candles);

        return ['first' => $series, 'second' => $series];
    }
}
