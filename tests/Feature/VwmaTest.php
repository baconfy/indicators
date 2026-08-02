<?php

declare(strict_types=1);

use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Indicators\Vwma;
use Baconfy\Indicators\Math\Decimal;
use Baconfy\Indicators\Tests\Support\CandleFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

function vwmaFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/vwma.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The fixture only asserts the converged tail — its nulls mean "not asserted by
 * the reference", not "warm-up". Warm-up is pinned separately, on synthetic data.
 *
 * @return array<int, string>
 */
function vwmaAssertedValues(array $fixture): array
{
    return array_filter($fixture['expected'], static fn (?string $value): bool => $value !== null);
}

/**
 * The naive definition: re-sums the whole window at every index. The rolling
 * implementation must be byte-identical to it (D10 — BigDecimal carries no drift).
 *
 * @param  list<Candle>  $candles
 * @return list<BigDecimal|null>
 */
function vwmaByReSummingTheWindow(array $candles, int $period): array
{
    $series = [];

    foreach (array_keys($candles) as $index) {
        if ($index < $period - 1) {
            $series[] = null;

            continue;
        }

        $weighted = BigDecimal::zero();
        $volume = BigDecimal::zero();

        foreach (array_slice($candles, $index - $period + 1, $period) as $candle) {
            $weighted = $weighted->plus($candle->close->multipliedBy($candle->volume));
            $volume = $volume->plus($candle->volume);
        }

        $series[] = $volume->isZero() ? null : Decimal::divide($weighted, $volume);
    }

    return $series;
}

it('matches the golden fixture at the reference precision', function () {
    $fixture = vwmaFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $precision = $fixture['precision'];
    $expected = vwmaAssertedValues($fixture);

    expect($expected)->not->toBeEmpty();

    $computed = (new Vwma(period: $fixture['parameters']['period']))->compute($candles);

    $actual = [];

    foreach (array_keys($expected) as $index) {
        $actual[$index] = $computed[$index]?->toScale($precision, RoundingMode::HalfUp)->__toString();
    }

    expect($actual)->toBe($expected);
});

it('holds null for the whole warm-up and yields the first value at period - 1', function () {
    $candles = CandleFactory::fromClosesAndVolumes([10, 20, 30, 40, 50], [1, 1, 1, 1, 1]);

    $result = (new Vwma(period: 3))->compute($candles);

    expect(array_slice($result, 0, 2))->each->toBeNull()
        ->and($result[2])->not->toBeNull()
        ->and((string) $result[2])->toBe('20.000000000000');
});

it('weighs each close by its own volume across the window, by hand', function () {
    $candles = CandleFactory::fromClosesAndVolumes([10, 20, 30], [1, 3, 1]);

    $result = (new Vwma(period: 2))->compute($candles);

    // (10*1 + 20*3) / (1+3) = 70/4; then (20*3 + 30*1) / (3+1) = 90/4.
    expect($result[0])->toBeNull()
        ->and((string) $result[1])->toBe('17.500000000000')
        ->and((string) $result[2])->toBe('22.500000000000');
});

it('yields null for a window whose total volume is zero, then recovers', function () {
    $candles = CandleFactory::fromClosesAndVolumes([10, 20, 30, 40], [5, 0, 0, 7]);

    $result = (new Vwma(period: 2))->compute($candles);

    // The undefined value is mid-series, not warm-up: index 3 is defined again.
    expect($result[0])->toBeNull()
        ->and((string) $result[1])->toBe('10.000000000000')
        ->and($result[2])->toBeNull()
        ->and((string) $result[3])->toBe('40.000000000000');
});

it('yields an all-null series when no candle carries any volume', function () {
    $candles = CandleFactory::fromClosesAndVolumes([10, 20, 30, 40, 50], [0, 0, 0, 0, 0]);

    expect((new Vwma(period: 3))->compute($candles))
        ->toHaveCount(5)
        ->each->toBeNull();
});

it('rolls its two sums to exactly what re-summing the window yields', function (int $period) {
    $fixture = vwmaFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);

    $rolled = (new Vwma(period: $period))->compute($candles);
    $naive = vwmaByReSummingTheWindow($candles, $period);

    $toStrings = static fn (array $series): array => array_map(
        static fn (?BigDecimal $value): ?string => $value?->__toString(),
        $series,
    );

    expect($toStrings($rolled))->toBe($toStrings($naive));
})->with([1, 2, 7, 20, 50]);

it('keeps every value at the policy scale, however long the series', function () {
    $fixture = vwmaFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);

    $result = (new Vwma(period: 20))->compute($candles);
    $values = array_values(array_filter($result, static fn (?BigDecimal $value): bool => $value !== null));

    expect($values)->toHaveCount(280);

    foreach ($values as $value) {
        expect($value->getScale())->toBe(Decimal::SCALE);
    }
});

it('collapses to the closes when the period is 1, whatever the volumes', function () {
    $candles = CandleFactory::fromClosesAndVolumes([42000, 41500.5, 41999.25], ['0.5', 3, '12.75']);

    $result = (new Vwma(period: 1))->compute($candles);

    $actual = array_map(
        static fn (?BigDecimal $value): string => $value->toScale(2, RoundingMode::HalfUp)->__toString(),
        $result,
    );

    expect($actual)->toBe(['42000.00', '41500.50', '41999.25']);
});

it('returns all nulls when there are fewer candles than the period', function () {
    $candles = CandleFactory::fromClosesAndVolumes([10, 20], [3, 4]);

    $result = (new Vwma(period: 5))->compute($candles);

    expect($result)->toHaveCount(2)
        ->and($result)->each->toBeNull();
});

it('returns an empty series for empty input', function () {
    expect((new Vwma(period: 20))->compute([]))->toBe([]);
});

it('always returns a series aligned with its input', function (int $period, int $length) {
    $candles = CandleFactory::fromCloses(range(1, $length));

    expect((new Vwma(period: $period))->compute($candles))->toHaveCount($length);
})->with([
    [1, 1],
    [3, 3],
    [9, 4],
    [2, 20],
    [20, 200],
]);

it('rejects a period below 1 at construction', function (int $period) {
    expect(fn () => new Vwma(period: $period))
        ->toThrow(InvalidParameterException::class);
})->with([0, -1, -20]);

it('names the offending class and value in the message', function () {
    expect(fn () => new Vwma(period: 0))
        ->toThrow(InvalidParameterException::class, 'Baconfy\Indicators\Indicators\Vwma requires a period >= 1, 0 given.');
});
