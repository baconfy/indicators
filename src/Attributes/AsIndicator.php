<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsIndicator
{
    public function __construct(public string $name) {}
}
