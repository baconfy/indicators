<?php

declare(strict_types=1);

use Baconfy\Indicators\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

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

it('normalizes a recurrence state down to the policy scale', function () {
    $result = Decimal::round(BigDecimal::of('3.166666666667500000000000'));

    expect((string) $result)->toBe('3.166666666668')
        ->and($result->getScale())->toBe(Decimal::SCALE);
});

it('normalizes HALF_UP, not HALF_EVEN, on an exact tie', function () {
    // Both ties would land on the even neighbour under HALF_EVEN (…000 and …002).
    expect((string) Decimal::round(BigDecimal::of('0.0000000000005')))->toBe('0.000000000001')
        ->and((string) Decimal::round(BigDecimal::of('0.0000000000025')))->toBe('0.000000000003');
});

it('pads a shorter value up to the policy scale without changing it', function () {
    $result = Decimal::round(BigDecimal::of('2.5'));

    expect((string) $result)->toBe('2.500000000000')
        ->and($result->isEqualTo('2.5'))->toBeTrue();
});

it('keeps a perfect square exact, padded to the policy scale', function () {
    $result = Decimal::sqrt(BigDecimal::of(9));

    expect((string) $result)->toBe('3.000000000000')
        ->and($result->getScale())->toBe(Decimal::SCALE);
});

it('rounds the root instead of truncating it', function () {
    // sqrt(3) = 1.7320508075688772... — Brick's sqrt($scale) truncates, so asking
    // it for scale 12 directly would yield ...568. The two guard digits let the
    // policy rounding see the 8 that follows and carry it up to ...569.
    $result = Decimal::sqrt(BigDecimal::of(3));

    expect((string) $result)->toBe('1.732050807569')
        ->and((string) BigDecimal::of(3)->sqrt(Decimal::SCALE, RoundingMode::Down))->toBe('1.732050807568');
});

it('takes the root of zero', function () {
    expect((string) Decimal::sqrt(BigDecimal::zero()))->toBe('0.000000000000');
});

it('accepts the divisor as int or as BigDecimal', function () {
    $fromInt = Decimal::divide(BigDecimal::of(1), 3);
    $fromBigDecimal = Decimal::divide(BigDecimal::of(1), BigDecimal::of(3));

    expect((string) $fromInt)->toBe('0.333333333333')
        ->and((string) $fromBigDecimal)->toBe((string) $fromInt);
});
