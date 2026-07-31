<?php

declare(strict_types=1);

use Baconfy\Indicators\Data\Candle;
use Brick\Math\BigDecimal;

function candle(): Candle
{
    return new Candle(
        openTime: new DateTimeImmutable('2024-01-01T00:00:00+00:00'),
        open: BigDecimal::of('42000.10'),
        high: BigDecimal::of('42500.55'),
        low: BigDecimal::of('41800.00'),
        close: BigDecimal::of('42300.25'),
        volume: BigDecimal::of('1234.56789'),
    );
}

it('exposes every field it was constructed with', function () {
    $candle = candle();

    expect($candle->openTime->format(DATE_ATOM))->toBe('2024-01-01T00:00:00+00:00')
        ->and((string) $candle->open)->toBe('42000.10')
        ->and((string) $candle->high)->toBe('42500.55')
        ->and((string) $candle->low)->toBe('41800.00')
        ->and((string) $candle->close)->toBe('42300.25')
        ->and((string) $candle->volume)->toBe('1234.56789');
});

it('is readonly', function (string $property) {
    $candle = candle();

    expect(fn () => $candle->{$property} = BigDecimal::of('1'))
        ->toThrow(Error::class);
})->with(['open', 'high', 'low', 'close', 'volume']);

it('is a final readonly class', function () {
    $reflection = new ReflectionClass(Candle::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});
