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

    /**
     * Re-quantizes a recurrent state to the policy scale.
     *
     * A recurrence that never divides (the EMA one multiplies and adds) grows
     * its scale by SCALE digits per bar, turning an O(n) pass into O(n²) of
     * digit-work. Normalizing every step bounds it; the error is below 5e-13
     * and invisible at any reference precision.
     */
    public static function round(BigDecimal $value): BigDecimal
    {
        return $value->toScale(self::SCALE, RoundingMode::HalfUp);
    }
}
