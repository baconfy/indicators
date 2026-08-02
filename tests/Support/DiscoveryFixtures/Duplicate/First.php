<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Tests\Support\DiscoveryFixtures\Duplicate;

use Baconfy\Indicators\Attributes\AsIndicator;
use Baconfy\Indicators\Contracts\Indicator;

#[AsIndicator('clash')]
final readonly class First implements Indicator
{
    public function compute(array $candles): array
    {
        return [];
    }
}
