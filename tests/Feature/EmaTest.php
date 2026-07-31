<?php

declare(strict_types=1);

use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Indicators\Ema;
use Baconfy\Indicators\Indicators\Sma;
use Baconfy\Indicators\Math\Decimal;
use Baconfy\Indicators\Tests\Support\CandleFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

function emaFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/ema.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The fixture only asserts the converged tail — its nulls mean "not asserted by
 * the reference", not "warm-up". Warm-up is pinned separately, on synthetic data.
 *
 * @return array<int, string>
 */
function emaAssertedValues(array $fixture): array
{
    return array_filter($fixture['expected'], static fn (?string $value): bool => $value !== null);
}

it('matches the golden fixture at the reference precision', function () {
    $fixture = emaFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $precision = $fixture['precision'];
    $expected = emaAssertedValues($fixture);

    expect($expected)->not->toBeEmpty();

    $computed = (new Ema(period: $fixture['parameters']['period']))->compute($candles);

    $actual = [];

    foreach (array_keys($expected) as $index) {
        $actual[$index] = $computed[$index]?->toScale($precision, RoundingMode::HalfUp)->__toString();
    }

    expect($actual)->toBe($expected);
});

it('seeds index period - 1 with exactly what the Sma yields there', function () {
    $fixture = emaFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $period = $fixture['parameters']['period'];

    $ema = (new Ema(period: $period))->compute($candles);
    $sma = (new Sma(period: $period))->compute($candles);

    expect($ema[$period - 1])->not->toBeNull()
        ->and((string) $ema[$period - 1])->toBe((string) $sma[$period - 1]);
});

it('seeds on the Sma for any period', function (int $period) {
    $candles = CandleFactory::fromCloses([3, 1, 4, 1, 5, 9, 2, 6, 5, 3, 5, 8, 9, 7, 9]);

    $ema = (new Ema(period: $period))->compute($candles);
    $sma = (new Sma(period: $period))->compute($candles);

    expect((string) $ema[$period - 1])->toBe((string) $sma[$period - 1]);
})->with([1, 2, 3, 9, 14]);

it('holds null for the whole warm-up and yields the first value at period - 1', function () {
    $candles = CandleFactory::fromCloses([1, 2, 3, 4, 5, 6, 7, 8]);

    $result = (new Ema(period: 5))->compute($candles);

    expect(array_slice($result, 0, 4))->each->toBeNull()
        ->and($result[4])->not->toBeNull()
        ->and((string) $result[4])->toBe('3.000000000000');
});

it('returns all nulls when there are fewer candles than the period', function () {
    $candles = CandleFactory::fromCloses([10, 20]);

    $result = (new Ema(period: 5))->compute($candles);

    expect($result)->toHaveCount(2)
        ->and($result)->each->toBeNull();
});

it('returns an empty series for empty input', function () {
    expect((new Ema(period: 5))->compute([]))->toBe([]);
});

it('always returns a series aligned with its input', function (int $period, int $length) {
    $candles = CandleFactory::fromCloses(range(1, $length));

    expect((new Ema(period: $period))->compute($candles))->toHaveCount($length);
})->with([
    [1, 1],
    [3, 3],
    [9, 4],
    [2, 20],
    [14, 200],
]);

it('builds the multiplier through the package math policy', function () {
    $candles = CandleFactory::fromCloses([1, 2, 4]);

    $result = (new Ema(period: 2))->compute($candles);

    // k = 2/3 rounded by the policy = 0.666666666667; seed = (1+2)/2 = 1.5
    // The raw recurrence gives (4 - 1.5) * 0.666666666667 + 1.5 = 3.1666666666675
    // at scale 24; the policy re-quantizes the state back to scale 12, and the
    // trailing 5 rounds up.
    expect((string) $result[1])->toBe('1.500000000000')
        ->and((string) $result[2])->toBe('3.166666666668');
});

it('keeps every value at the policy scale, however long the series', function () {
    $candles = CandleFactory::fromCloses(range(1, 50));

    $result = (new Ema(period: 9))->compute($candles);
    $values = array_values(array_filter($result, static fn (?BigDecimal $value): bool => $value !== null));

    expect($values)->toHaveCount(42);

    foreach ($values as $value) {
        expect($value->getScale())->toBe(Decimal::SCALE);
    }
});

it('collapses to the closes when the period is 1', function () {
    $closes = [42000, 41500.5, 41999.25];
    $candles = CandleFactory::fromCloses($closes);

    $result = (new Ema(period: 1))->compute($candles);

    $actual = array_map(
        static fn (?BigDecimal $value): string => $value->toScale(2, RoundingMode::HalfUp)->__toString(),
        $result,
    );

    expect($actual)->toBe(['42000.00', '41500.50', '41999.25']);
});

it('rejects a period below 1 at construction', function (int $period) {
    expect(fn () => new Ema(period: $period))
        ->toThrow(InvalidParameterException::class);
})->with([0, -1, -14]);

it('names the offending class and value in the message', function () {
    expect(fn () => new Ema(period: 0))
        ->toThrow(InvalidParameterException::class, 'Baconfy\Indicators\Indicators\Ema requires a period >= 1, 0 given.');
});
