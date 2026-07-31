<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Math;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The package-wide scale and rounding policy.
 *
 * Every division in every indicator goes through here, so that two indicators
 * computing the same value can never diverge in the 10th digit.
 *
 * @internal
 */
final class Decimal
{
    public const int SCALE = 12;

    public static function divide(BigDecimal $a, BigDecimal|int $b): BigDecimal
    {
        return $a->dividedBy($b, self::SCALE, RoundingMode::HalfUp);
    }
}
