<?php

declare(strict_types=1);

use Baconfy\Indicators\Math\Decimal;
use Brick\Math\BigDecimal;

it('rounds a repeating decimal at scale 12', function () {
    $result = Decimal::divide(BigDecimal::of(2), 3);

    expect((string) $result)->toBe('0.666666666667');
});

it('rounds HALF_UP, not HALF_EVEN, on an exact tie', function () {
    $result = Decimal::divide(BigDecimal::of('0.0000000000005'), 1);

    expect((string) $result)->toBe('0.000000000001');
});

it('keeps an exact division exact, padded to the policy scale', function () {
    $result = Decimal::divide(BigDecimal::of(10), 4);

    expect((string) $result)->toBe('2.500000000000')
        ->and($result->isEqualTo('2.5'))->toBeTrue();
});

it('accepts the divisor as int or as BigDecimal', function () {
    $fromInt = Decimal::divide(BigDecimal::of(1), 3);
    $fromBigDecimal = Decimal::divide(BigDecimal::of(1), BigDecimal::of(3));

    expect((string) $fromInt)->toBe('0.333333333333')
        ->and((string) $fromBigDecimal)->toBe((string) $fromInt);
});
