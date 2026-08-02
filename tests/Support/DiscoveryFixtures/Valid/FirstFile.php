<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Tests\Support\DiscoveryFixtures\Valid;

use Baconfy\Indicators\Attributes\AsIndicator;
use Baconfy\Indicators\Contracts\Indicator;

/** Declares a name that sorts AFTER its filename, pinning the sort-by-name order. */
#[AsIndicator('second')]
final readonly class FirstFile implements Indicator
{
    public function compute(array $candles): array
    {
        return [];
    }
}
