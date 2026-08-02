<?php

declare(strict_types=1);

use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Indicators\Rma;
use Baconfy\Indicators\Indicators\Sma;
use Baconfy\Indicators\Math\Decimal;
use Baconfy\Indicators\Tests\Support\CandleFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

function rmaFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/rma.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The fixture only asserts the converged tail — its nulls mean "not asserted by
 * the reference", not "warm-up". Warm-up is pinned separately, on synthetic data.
 *
 * @return array<int, string>
 */
function rmaAssertedValues(array $fixture): array
{
    return array_filter($fixture['expected'], static fn (?string $value): bool => $value !== null);
}

it('matches the golden fixture at the reference precision', function () {
    $fixture = rmaFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $precision = $fixture['precision'];
    $expected = rmaAssertedValues($fixture);

    expect($expected)->not->toBeEmpty();

    $computed = (new Rma(period: $fixture['parameters']['period']))->compute($candles);

    $actual = [];

    foreach (array_keys($expected) as $index) {
        $actual[$index] = $computed[$index]?->toScale($precision, RoundingMode::HalfUp)->__toString();
    }

    expect($actual)->toBe($expected);
});

it('lands its first value exactly at index period - 1, with no one-bar shift like the Rsi and the Atr', function () {
    $fixture = rmaFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $period = $fixture['parameters']['period'];

    $result = (new Rma(period: $period))->compute($candles);

    expect($result[$period - 2])->toBeNull()
        ->and($result[$period - 1])->not->toBeNull();
});

it('seeds index period - 1 with exactly what the Sma yields there', function (int $period) {
    $candles = CandleFactory::fromCloses([3, 1, 4, 1, 5, 9, 2, 6, 5, 3, 5, 8, 9, 7, 9]);

    $rma = (new Rma(period: $period))->compute($candles);
    $sma = (new Sma(period: $period))->compute($candles);

    expect($rma[$period - 1])->not->toBeNull()
        ->and((string) $rma[$period - 1])->toBe((string) $sma[$period - 1]);
})->with([1, 2, 3, 9, 14]);

it('holds null for the whole warm-up and yields the first value at period - 1', function () {
    $candles = CandleFactory::fromCloses([1, 2, 3, 4, 5, 6, 7, 8]);

    $result = (new Rma(period: 5))->compute($candles);

    expect(array_slice($result, 0, 4))->each->toBeNull()
        ->and($result[4])->not->toBeNull()
        ->and((string) $result[4])->toBe('3.000000000000');
});

it('smooths the raw closes step by step, by hand', function () {
    $candles = CandleFactory::fromCloses([1, 2, 3, 4, 5]);

    $result = (new Rma(period: 2))->compute($candles);

    // seed = (1 + 2) / 2 = 1.5; then rma = (prev * 1 + close) / 2 at every step.
    expect($result[0])->toBeNull()
        ->and((string) $result[1])->toBe('1.500000000000')
        ->and((string) $result[2])->toBe('2.250000000000')
        ->and((string) $result[3])->toBe('3.125000000000')
        ->and((string) $result[4])->toBe('4.062500000000');
});

it('returns all nulls when there are fewer candles than the period', function () {
    $candles = CandleFactory::fromCloses([10, 20]);

    $result = (new Rma(period: 5))->compute($candles);

    expect($result)->toHaveCount(2)
        ->and($result)->each->toBeNull();
});

it('returns an empty series for empty input', function () {
    expect((new Rma(period: 14))->compute([]))->toBe([]);
});

it('always returns a series aligned with its input', function (int $period, int $length) {
    $candles = CandleFactory::fromCloses(range(1, $length));

    expect((new Rma(period: $period))->compute($candles))->toHaveCount($length);
})->with([
    [1, 1],
    [3, 3],
    [9, 4],
    [2, 20],
    [14, 200],
]);

it('keeps every value at the policy scale, however long the series', function () {
    $candles = CandleFactory::fromCloses(range(1, 50));

    $result = (new Rma(period: 9))->compute($candles);
    $values = array_values(array_filter($result, static fn (?BigDecimal $value): bool => $value !== null));

    expect($values)->toHaveCount(42);

    foreach ($values as $value) {
        expect($value->getScale())->toBe(Decimal::SCALE);
    }
});

it('collapses to the closes when the period is 1', function () {
    $closes = [42000, 41500.5, 41999.25];
    $candles = CandleFactory::fromCloses($closes);

    $result = (new Rma(period: 1))->compute($candles);

    $actual = array_map(
        static fn (?BigDecimal $value): string => $value->toScale(2, RoundingMode::HalfUp)->__toString(),
        $result,
    );

    expect($actual)->toBe(['42000.00', '41500.50', '41999.25']);
});

it('rejects a period below 1 at construction', function (int $period) {
    expect(fn () => new Rma(period: $period))
        ->toThrow(InvalidParameterException::class);
})->with([0, -1, -14]);

it('names the offending class and value in the message', function () {
    expect(fn () => new Rma(period: 0))
        ->toThrow(InvalidParameterException::class, 'Baconfy\Indicators\Indicators\Rma requires a period >= 1, 0 given.');
});
