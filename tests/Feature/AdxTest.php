<?php

declare(strict_types=1);

use Baconfy\Indicators\Data\Candle;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Indicators\Adx;
use Baconfy\Indicators\Math\Decimal;
use Baconfy\Indicators\Tests\Support\CandleFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

function adxFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/adx.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The directional movement reads high, low and close, so the hand-checkable
 * series are written as [high, low, close] triples.
 *
 * @param  list<array{0: string|int|float, 1: string|int|float, 2: string|int|float}>  $bars
 * @return list<Candle>
 */
function adxCandles(array $bars): array
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
 * @param  list<BigDecimal|null>  $series
 * @return list<string|null>
 */
function adxStrings(array $series): array
{
    return array_map(static fn (?BigDecimal $value): ?string => $value?->__toString(), $series);
}

it('matches the golden fixture on every series at the reference precision', function (string $name) {
    $fixture = adxFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $precision = $fixture['precision'];

    // The fixture only asserts the converged tail — its nulls mean "not asserted
    // by the reference", not "warm-up". Warm-up is pinned separately.
    $expected = array_filter($fixture['expected'][$name], static fn (?string $value): bool => $value !== null);

    expect($expected)->not->toBeEmpty();

    $computed = (new Adx(period: $fixture['parameters']['period']))->compute($candles)[$name];

    $actual = [];

    foreach (array_keys($expected) as $index) {
        $actual[$index] = $computed[$index]?->toScale($precision, RoundingMode::HalfUp)->__toString();
    }

    expect($actual)->toBe($expected);
})->with(['adx', 'plus_di', 'minus_di']);

it('returns exactly the three named series of the contract', function () {
    $series = (new Adx)->compute(CandleFactory::fromCloses(range(1, 30)));

    expect(array_keys($series))->toBe(['adx', 'plus_di', 'minus_di']);
});

it('takes each branch of the directional movement in turn', function () {
    // period 1 makes every RMA the identity, so the DIs are 100·DM/TR bar by bar
    // and the branch taken is readable straight off the public series.
    $candles = adxCandles([
        [100, 90, 95],    // bar 0: both DMs defined as zero, TR = high - low = 10
        [110, 95, 105],   // up 10 > down -5  → +DM wins, TR 15
        [105, 80, 85],    // down 15 > up -5  → −DM wins, TR 25
        [100, 90, 95],    // inside bar: up -5, down -10 → both zero, TR 15
        [110, 80, 100],   // outside bar: up 10 == down 10 → the tie zeroes both, TR 30
    ]);

    $series = (new Adx(period: 1))->compute($candles);

    expect(adxStrings($series['plus_di']))->toBe([
        '0.000000000000',
        '66.666666666667',  // 100 · 10 / 15
        '0.000000000000',
        '0.000000000000',
        '0.000000000000',
    ])->and(adxStrings($series['minus_di']))->toBe([
        '0.000000000000',
        '0.000000000000',
        '60.000000000000',  // 100 · 15 / 25
        '0.000000000000',
        '0.000000000000',
    ]);
});

it('yields a dx of exactly zero at the policy scale when both dis are zero', function () {
    $candles = adxCandles([
        [100, 90, 95],
        [110, 95, 105],
        [105, 80, 85],
        [100, 90, 95],
        [110, 80, 100],
    ]);

    // At period 1 the second smoothing is the identity too, so adx IS the dx.
    $series = (new Adx(period: 1))->compute($candles);

    expect(adxStrings($series['adx']))->toBe([
        '0.000000000000',    // both DIs zero → defined as zero, not undefined
        '100.000000000000',
        '100.000000000000',
        '0.000000000000',    // inside bar, both DIs zero again
        '0.000000000000',    // tie bar, likewise
    ]);
});

it('yields three all-null series for a series that never moves', function () {
    $candles = adxCandles(array_fill(0, 20, [100, 100, 100]));

    $series = (new Adx(period: 3))->compute($candles);

    foreach (['adx', 'plus_di', 'minus_di'] as $name) {
        expect($series[$name])->toHaveCount(20)
            ->and($series[$name])->each->toBeNull();
    }
});

it('stops the second smoothing at the end of the contiguous dx slice', function () {
    // At period 1 the RMA degenerates to the identity, so a flat bar sitting
    // exactly on the previous close sends the smoothed true range back to zero
    // mid-series — the one case where the dx nulls are not just a leading prefix.
    $candles = adxCandles([
        [110, 90, 100],
        [100, 100, 100],  // true range 0: high == low == the previous close
        [120, 80, 110],
    ]);

    $series = (new Adx(period: 1))->compute($candles);

    expect($series['plus_di'][1])->toBeNull()
        ->and($series['minus_di'][1])->toBeNull()
        ->and($series['adx'][0])->not->toBeNull()
        ->and($series['adx'][1])->toBeNull()
        // Outside the contiguous slice the second smoothing does not resume.
        ->and($series['adx'][2])->toBeNull();
});

it('lands the dis at period - 1 and the adx at 2 * period - 2', function (int $period) {
    $candles = CandleFactory::fromRows(adxFixture()['candles']);

    $series = (new Adx(period: $period))->compute($candles);

    expect(array_slice($series['plus_di'], 0, $period - 1))->each->toBeNull()
        ->and($series['plus_di'][$period - 1])->not->toBeNull()
        ->and(array_slice($series['minus_di'], 0, $period - 1))->each->toBeNull()
        ->and($series['minus_di'][$period - 1])->not->toBeNull()
        ->and(array_slice($series['adx'], 0, 2 * $period - 2))->each->toBeNull()
        ->and($series['adx'][2 * $period - 2])->not->toBeNull();
})->with([2, 3, 5, 14]);

it('keeps every series aligned with its input', function (int $length) {
    $series = (new Adx)->compute(CandleFactory::fromCloses(range(1, $length)));

    foreach (['adx', 'plus_di', 'minus_di'] as $name) {
        expect($series[$name])->toHaveCount($length);
    }
})->with([1, 5, 13, 14, 26, 27, 299]);

it('yields three aligned all-null series when there is not enough data', function (int $length) {
    $series = (new Adx)->compute(CandleFactory::fromCloses(range(1, $length)));

    foreach (['adx', 'plus_di', 'minus_di'] as $name) {
        expect($series[$name])->toHaveCount($length)
            ->and($series[$name])->each->toBeNull();
    }
})->with([1, 5, 13]);

it('holds the adx null past the dis, until its own second smoothing lands', function () {
    $candles = CandleFactory::fromRows(adxFixture()['candles']);

    $series = (new Adx(period: 14))->compute($candles);

    // The DIs are already running while the adx is still warming up.
    expect($series['plus_di'][13])->not->toBeNull()
        ->and($series['adx'][13])->toBeNull()
        ->and($series['adx'][25])->toBeNull()
        ->and($series['adx'][26])->not->toBeNull();
});

it('returns three empty series for empty input', function () {
    expect((new Adx)->compute([]))->toBe(['adx' => [], 'plus_di' => [], 'minus_di' => []]);
});

it('keeps every non-null value of every series at the policy scale', function () {
    $fixture = adxFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);

    $series = (new Adx(period: $fixture['parameters']['period']))->compute($candles);

    foreach (['adx', 'plus_di', 'minus_di'] as $name) {
        $values = array_values(array_filter($series[$name], static fn (?BigDecimal $value): bool => $value !== null));

        expect($values)->not->toBeEmpty();

        foreach ($values as $value) {
            expect($value->getScale())->toBe(Decimal::SCALE);
        }
    }
});

it('defaults to a period of 14', function () {
    expect((new Adx)->period)->toBe(14);
});

it('rejects a period below 1 at construction', function (int $period) {
    expect(fn () => new Adx(period: $period))->toThrow(InvalidParameterException::class);
})->with([0, -1, -14]);

it('names the offending class and value in the message', function () {
    expect(fn () => new Adx(period: 0))
        ->toThrow(InvalidParameterException::class, 'Baconfy\Indicators\Indicators\Adx requires a period >= 1, 0 given.');
});
