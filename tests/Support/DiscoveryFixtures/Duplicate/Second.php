<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Tests\Support\DiscoveryFixtures\Duplicate;

use Baconfy\Indicators\Attributes\AsIndicator;
use Baconfy\Indicators\Contracts\MultiIndicator;

/** Steals the name First already took — the collision must be loud, not last-wins. */
#[AsIndicator('clash')]
final readonly class Second implements MultiIndicator
{
    public function compute(array $candles): array
    {
        return [];
    }
}
