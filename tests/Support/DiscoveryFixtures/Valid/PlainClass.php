<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Tests\Support\DiscoveryFixtures\Valid;

/** Implements neither contract: discovery must walk straight past it, silently. */
final readonly class PlainClass
{
    public function compute(array $candles): array
    {
        return [];
    }
}
