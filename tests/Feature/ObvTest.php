<?php

declare(strict_types=1);

use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Indicators\Obv;
use Baconfy\Indicators\Math\Decimal;
use Baconfy\Indicators\Tests\Support\CandleFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

function obvFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/obv.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The fixture only asserts the converged tail — its nulls mean "not asserted by
 * the reference", never "warm-up": an Obv series holds no null at all.
 *
 * @return array<int, string>
 */
function obvAssertedValues(array $fixture): array
{
    return array_filter($fixture['expected'], static fn (?string $value): bool => $value !== null);
}

/**
 * Obv is the second indicator reading a field beyond the close, so it needs
 * candles whose close AND volume both matter.
 *
 * @param  list<string|int|float>  $closes
 * @param  list<string|int|float>  $volumes
 * @return list<Candle>
 */
function obvCandles(array $closes, array $volumes): array
{
    $candles = [];

    foreach (array_values($closes) as $index => $close) {
        $candles[] = new Candle(
            openTime: new DateTimeImmutable(sprintf('2024-01-01T00:00:00+00:00 +%d days', $index)),
            open: BigDecimal::of((string) $close),
            high: BigDecimal::of((string) $close),
            low: BigDecimal::of((string) $close),
            close: BigDecimal::of((string) $close),
            volume: BigDecimal::of((string) $volumes[$index]),
        );
    }

    return $candles;
}

it('matches the golden fixture at the reference precision', function () {
    $fixture = obvFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $precision = $fixture['precision'];
    $expected = obvAssertedValues($fixture);

    expect($expected)->not->toBeEmpty();

    $computed = (new Obv)->compute($candles);

    $actual = [];

    foreach (array_keys($expected) as $index) {
        $actual[$index] = $computed[$index]?->toScale($precision, RoundingMode::HalfUp)->__toString();
    }

    expect($actual)->toBe($expected);
});

it('is defined from bar 0 and never holds a null anywhere in the series', function () {
    $fixture = obvFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);

    $result = (new Obv)->compute($candles);

    expect($result[0])->not->toBeNull()
        ->and((string) $result[0])->toBe('0.000000000000')
        ->and(array_filter($result, static fn (?BigDecimal $value): bool => $value === null))->toBe([]);
});

it('adds the volume on an up close, subtracts it on a down close and carries it flat', function () {
    $candles = obvCandles([10, 12, 11, 11, 15], [5, 100, 40, 7, 60]);

    $result = (new Obv)->compute($candles);

    $actual = array_map(
        static fn (BigDecimal $value): string => $value->toScale(0, RoundingMode::HalfUp)->__toString(),
        $result,
    );

    // 0; up +100; down -40; flat carries; up +60.
    expect($actual)->toBe(['0', '100', '60', '60', '120']);
});

it('carries the very same value across a flat close', function () {
    $candles = obvCandles([10, 12, 12, 12, 9], [5, 100, 40, 7, 60]);

    $result = (new Obv)->compute($candles);

    expect($result[2])->toEqual($result[1])
        ->and($result[3])->toEqual($result[2])
        ->and((string) $result[3])->toBe('100.000000000000')
        ->and((string) $result[4])->toBe('40.000000000000');
});

it('keeps every value at the policy scale, even though it only ever adds', function () {
    $fixture = obvFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);

    $result = (new Obv)->compute($candles);

    expect($result)->not->toBeEmpty();

    foreach ($result as $value) {
        expect($value->getScale())->toBe(Decimal::SCALE);
    }
});

it('returns a zeroed single value for a single candle', function () {
    $candles = obvCandles([42000], ['1.5']);

    $result = (new Obv)->compute($candles);

    expect(array_map(static fn (BigDecimal $value): string => (string) $value, $result))
        ->toBe(['0.000000000000']);
});

it('returns an empty series for empty input', function () {
    expect((new Obv)->compute([]))->toBe([]);
});

it('always returns a series aligned with its input', function (int $length) {
    $candles = CandleFactory::fromCloses(range(1, $length));

    expect((new Obv)->compute($candles))->toHaveCount($length);
})->with([1, 2, 3, 20, 200]);
