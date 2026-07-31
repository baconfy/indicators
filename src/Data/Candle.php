<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Data;

use Brick\Math\BigDecimal;
use DateTimeImmutable;

final readonly class Candle
{
    public function __construct(
        public DateTimeImmutable $openTime,
        public BigDecimal $open,
        public BigDecimal $high,
        public BigDecimal $low,
        public BigDecimal $close,
        public BigDecimal $volume,
    ) {}
}
