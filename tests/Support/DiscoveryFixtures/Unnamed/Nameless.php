<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Tests\Support\DiscoveryFixtures\Unnamed;

use Baconfy\Indicators\Contracts\Indicator;

/** The forgotten-registration case: a real indicator nobody gave a name to. */
final readonly class Nameless implements Indicator
{
    public function compute(array $candles): array
    {
        return [];
    }
}
