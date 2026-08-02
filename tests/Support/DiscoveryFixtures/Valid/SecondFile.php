<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Tests\Support\DiscoveryFixtures\Valid;

use Baconfy\Indicators\Attributes\AsIndicator;
use Baconfy\Indicators\Contracts\MultiIndicator;

/** The multi-series side of the pair, and the one that sorts first by name. */
#[AsIndicator('first')]
final readonly class SecondFile implements MultiIndicator
{
    public function compute(array $candles): array
    {
        return [];
    }
}
