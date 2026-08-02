<?php

declare(strict_types=1);

use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Indicators\Stochastic;
use Baconfy\Indicators\Math\Decimal;
use Baconfy\Indicators\Tests\Support\CandleFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

function stochasticFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/stochastic.json'), true, flags: JSON_THROW_ON_ERROR);
}

function stochasticFromFixture(array $fixture): Stochastic
{
    // The fixture documents its parameters in snake_case; the constructor speaks
    // PHP, so the mapping is explicit here rather than a blind named spread.
    return new Stochastic(
        kPeriod: $fixture['parameters']['k_period'],
        kSmooth: $fixture['parameters']['k_smooth'],
        dSmooth: $fixture['parameters']['d_smooth'],
    );
}

/**
 * The stochastic is the first indicator whose three price fields all matter, so
 * the hand-checkable series are written as [high, low, close] triples.
 *
 * @param  list<array{0: string|int|float, 1: string|int|float, 2: string|int|float}>  $bars
 * @return list<Candle>
 */
function stochasticCandles(array $bars): array
{
    $rows = [];

    foreach (array_values($bars) as $index => [$high, $low, $close]) {
        $rows[] = [
            'openTime' => sprintf('2024-01-01T00:00:00+00:00 +%d days', $index),
            'open' => (string) $close,
            'high' => (string) $high,
            'low' => (string) $low,
            'close' => (string) $close,
            'volume' => '1',
        ];
    }

    return CandleFactory::fromRows($rows);
}

/**
 * The naive definition of the rolling extremes: rescans the whole window at every
 * index. The monotonic deques must be byte-identical to it (D10 — the deque is a
 * complexity change, never a numeric one).
 *
 * @param  list<Candle>  $candles
 * @return list<BigDecimal|null>
 */
function stochasticRawKByRescanningTheWindow(array $candles, int $kPeriod): array
{
    $series = [];

    foreach (array_keys($candles) as $index) {
        if ($index < $kPeriod - 1) {
            $series[] = null;

            continue;
        }

        $window = array_slice($candles, $index - $kPeriod + 1, $kPeriod);

        $highest = $window[0]->high;
        $lowest = $window[0]->low;

        foreach ($window as $candle) {
            $highest = $candle->high->isGreaterThan($highest) ? $candle->high : $highest;
            $lowest = $candle->low->isLessThan($lowest) ? $candle->low : $lowest;
        }

        $range = $highest->minus($lowest);

        $series[] = $range->isZero()
            ? null
            : Decimal::divide($candles[$index]->close->minus($lowest)->multipliedBy(100), $range);
    }

    return $series;
}

/**
 * @param  list<BigDecimal|null>  $series
 * @return list<string|null>
 */
function stochasticStrings(array $series): array
{
    return array_map(static fn (?BigDecimal $value): ?string => $value?->__toString(), $series);
}

it('matches the golden fixture on every series at the reference precision', function (string $name) {
    $fixture = stochasticFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $precision = $fixture['precision'];

    // The fixture only asserts the converged tail — its nulls mean "not asserted
    // by the reference", not "warm-up". Warm-up is pinned separately.
    $expected = array_filter($fixture['expected'][$name], static fn (?string $value): bool => $value !== null);

    expect($expected)->not->toBeEmpty();

    $computed = stochasticFromFixture($fixture)->compute($candles)[$name];

    $actual = [];

    foreach (array_keys($expected) as $index) {
        $actual[$index] = $computed[$index]?->toScale($precision, RoundingMode::HalfUp)->__toString();
    }

    expect($actual)->toBe($expected);
})->with(['k', 'd']);

it('returns exactly the two named series of the contract', function () {
    $series = (new Stochastic)->compute(CandleFactory::fromCloses(range(1, 25)));

    expect(array_keys($series))->toBe(['k', 'd']);
});

it('places the raw k at the bottom, the top and the middle of the rolling range', function () {
    // kSmooth 1 makes k the raw k itself; dSmooth 1 makes d the same series again.
    $candles = stochasticCandles([
        [10, 0, 5],
        [10, 0, 0],   // close on the window low  → 0
        [10, 0, 10],  // close on the window high → 100
        [10, 0, 5],   // close mid-range          → 50
    ]);

    $series = (new Stochastic(kPeriod: 2, kSmooth: 1, dSmooth: 1))->compute($candles);

    expect(stochasticStrings($series['k']))->toBe([
        null,
        '0.000000000000',
        '100.000000000000',
        '50.000000000000',
    ])->and(stochasticStrings($series['d']))->toBe(stochasticStrings($series['k']));
});

it('yields null for a window with no range at all, then recovers', function () {
    $candles = stochasticCandles([
        [10, 0, 5],
        [10, 0, 5],
        [7, 7, 7],   // flat bar, but the window still spans the previous one
        [7, 7, 7],   // two flat bars: the window has zero range → undefined
        [12, 2, 7],
    ]);

    $series = (new Stochastic(kPeriod: 2, kSmooth: 1, dSmooth: 1))->compute($candles);

    // The undefined value is mid-series, not warm-up: index 4 is defined again.
    expect(stochasticStrings($series['k']))->toBe([
        null,
        '50.000000000000',
        '70.000000000000',
        null,
        '50.000000000000',
    ]);
});

it('poisons exactly the smoothing windows that contain a null raw k, no more', function () {
    $candles = stochasticCandles([
        [10, 0, 5],
        [12, 2, 7],
        [9, 9, 9],
        [9, 9, 9],   // raw k is null here, and only here
        [14, 4, 9],
        [16, 6, 11],
        [18, 8, 13],
        [20, 10, 15],
    ]);

    $rawK = (new Stochastic(kPeriod: 2, kSmooth: 1, dSmooth: 1))->compute($candles)['k'];

    expect(array_keys(array_filter($rawK, static fn (?BigDecimal $value): bool => $value === null)))->toBe([0, 3]);

    $k = (new Stochastic(kPeriod: 2, kSmooth: 3, dSmooth: 1))->compute($candles)['k'];

    // Windows 2, 3, 4 and 5 all contain the null at index 3 (index 2's contains
    // the warm-up null at 0); 6 and 7 are clean and must carry a value.
    expect(array_slice($k, 0, 6))->each->toBeNull()
        ->and($k[6])->not->toBeNull()
        ->and($k[7])->not->toBeNull();
});

it('rolls its extremes to exactly what rescanning the window yields', function (int $kPeriod) {
    $candles = CandleFactory::fromRows(stochasticFixture()['candles']);

    $rolled = (new Stochastic(kPeriod: $kPeriod, kSmooth: 1, dSmooth: 1))->compute($candles)['k'];
    $naive = stochasticRawKByRescanningTheWindow($candles, $kPeriod);

    expect(stochasticStrings($rolled))->toBe(stochasticStrings($naive));
})->with([1, 2, 3, 14, 50, 299]);

it('rolls its extremes through plateaus and ties exactly as a rescan does', function (int $kPeriod) {
    // Deliberately degenerate: repeated extremes, long plateaus and a spike that
    // dominates the whole window — the cases where a sloppy deque eviction breaks.
    $candles = stochasticCandles([
        [10, 5, 7], [10, 5, 6], [10, 5, 9], [12, 5, 11], [12, 3, 4],
        [12, 3, 12], [8, 3, 5], [8, 8, 8], [8, 8, 8], [20, 1, 19],
        [9, 4, 5], [9, 4, 9], [9, 4, 4], [11, 2, 10], [11, 2, 2],
        [11, 11, 11], [11, 11, 11], [15, 6, 14], [7, 6, 7], [7, 6, 6],
    ]);

    $rolled = (new Stochastic(kPeriod: $kPeriod, kSmooth: 1, dSmooth: 1))->compute($candles)['k'];
    $naive = stochasticRawKByRescanningTheWindow($candles, $kPeriod);

    expect(stochasticStrings($rolled))->toBe(stochasticStrings($naive));
})->with([1, 2, 3, 5, 14, 20]);

it('lands the raw k at kPeriod - 1', function () {
    $candles = CandleFactory::fromRows(stochasticFixture()['candles']);

    $k = (new Stochastic(kPeriod: 14, kSmooth: 1, dSmooth: 1))->compute($candles)['k'];

    expect(array_slice($k, 0, 13))->each->toBeNull()
        ->and($k[13])->not->toBeNull();
});

it('lands k at kPeriod + kSmooth - 2 and d at kPeriod + kSmooth + dSmooth - 3', function () {
    $candles = CandleFactory::fromRows(stochasticFixture()['candles']);

    $series = (new Stochastic)->compute($candles);

    expect(array_slice($series['k'], 0, 15))->each->toBeNull()
        ->and($series['k'][15])->not->toBeNull()
        ->and(array_slice($series['d'], 0, 17))->each->toBeNull()
        ->and($series['d'][17])->not->toBeNull();
});

it('keeps every series aligned with its input', function (int $length) {
    $series = (new Stochastic)->compute(CandleFactory::fromCloses(range(1, $length)));

    expect($series['k'])->toHaveCount($length)
        ->and($series['d'])->toHaveCount($length);
})->with([1, 5, 13, 14, 18, 299]);

it('yields two aligned all-null series when there is not enough data', function (int $length) {
    $series = (new Stochastic)->compute(CandleFactory::fromCloses(range(1, $length)));

    foreach (['k', 'd'] as $name) {
        expect($series[$name])->toHaveCount($length)
            ->and($series[$name])->each->toBeNull();
    }
    // 15 bars is the last length before k lands (kPeriod + kSmooth - 1 = 16 bars).
})->with([1, 5, 13, 15]);

it('returns two empty series for empty input', function () {
    expect((new Stochastic)->compute([]))->toBe(['k' => [], 'd' => []]);
});

it('keeps every non-null value of every series at the policy scale', function () {
    $fixture = stochasticFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);

    $series = stochasticFromFixture($fixture)->compute($candles);

    foreach (['k', 'd'] as $name) {
        $values = array_values(array_filter($series[$name], static fn (?BigDecimal $value): bool => $value !== null));

        expect($values)->not->toBeEmpty();

        foreach ($values as $value) {
            expect($value->getScale())->toBe(Decimal::SCALE);
        }
    }
});

it('defaults to 14, 3 and 3', function () {
    $stochastic = new Stochastic;

    expect($stochastic->kPeriod)->toBe(14)
        ->and($stochastic->kSmooth)->toBe(3)
        ->and($stochastic->dSmooth)->toBe(3);
});

it('rejects a kPeriod below 1 at construction', function (int $period) {
    expect(fn () => new Stochastic(kPeriod: $period))->toThrow(InvalidParameterException::class);
})->with([0, -1, -14]);

it('rejects a kSmooth below 1 at construction', function (int $period) {
    expect(fn () => new Stochastic(kSmooth: $period))->toThrow(InvalidParameterException::class);
})->with([0, -1, -3]);

it('rejects a dSmooth below 1 at construction', function (int $period) {
    expect(fn () => new Stochastic(dSmooth: $period))->toThrow(InvalidParameterException::class);
})->with([0, -1, -3]);

it('names the offending class, parameter and value in the message', function () {
    expect(fn () => new Stochastic(kPeriod: 0))
        ->toThrow(InvalidParameterException::class, 'Baconfy\Indicators\Indicators\Stochastic requires a kPeriod >= 1, 0 given.')
        ->and(fn () => new Stochastic(kSmooth: -1))
        ->toThrow(InvalidParameterException::class, 'Baconfy\Indicators\Indicators\Stochastic requires a kSmooth >= 1, -1 given.')
        ->and(fn () => new Stochastic(dSmooth: -3))
        ->toThrow(InvalidParameterException::class, 'Baconfy\Indicators\Indicators\Stochastic requires a dSmooth >= 1, -3 given.');
});
