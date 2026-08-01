<?php

declare(strict_types=1);

use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Indicators\Atr;
use Baconfy\Indicators\Math\Decimal;
use Baconfy\Indicators\Tests\Support\CandleFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

function atrFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/atr.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The fixture only asserts the converged tail — its nulls mean "not asserted by
 * the reference", not "warm-up". Warm-up is pinned separately, on synthetic data.
 *
 * @return array<int, string>
 */
function atrAssertedValues(array $fixture): array
{
    return array_filter($fixture['expected'], static fn (?string $value): bool => $value !== null);
}

/**
 * Four candles, hand-checkable, one per branch of the TR max().
 *
 *   index 0 — baseline, close 100
 *   index 1 — gap up:      high-low = 2,  |high-prevClose| = 20, |low-prevClose| = 18  → 20
 *   index 2 — gap down:    high-low = 2,  |high-prevClose| = 29, |low-prevClose| = 31  → 31
 *   index 3 — inside bar:  high-low = 10, |high-prevClose| = 6,  |low-prevClose| = 4   → 10
 *
 * @return list<\Baconfy\Indicators\Data\Candle>
 */
function atrBranchCandles(): array
{
    return CandleFactory::fromRows([
        ['openTime' => '2024-01-01T00:00:00+00:00', 'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '1'],
        ['openTime' => '2024-01-02T00:00:00+00:00', 'open' => '119', 'high' => '120', 'low' => '118', 'close' => '119', 'volume' => '1'],
        ['openTime' => '2024-01-03T00:00:00+00:00', 'open' => '89', 'high' => '90', 'low' => '88', 'close' => '89', 'volume' => '1'],
        ['openTime' => '2024-01-04T00:00:00+00:00', 'open' => '90', 'high' => '95', 'low' => '85', 'close' => '90', 'volume' => '1'],
    ]);
}

it('matches the golden fixture at the reference precision', function () {
    $fixture = atrFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $precision = $fixture['precision'];
    $expected = atrAssertedValues($fixture);

    expect($expected)->not->toBeEmpty();

    $computed = (new Atr(period: $fixture['parameters']['period']))->compute($candles);

    $actual = [];

    foreach (array_keys($expected) as $index) {
        $actual[$index] = $computed[$index]?->toScale($precision, RoundingMode::HalfUp)->__toString();
    }

    expect($actual)->toBe($expected);
});

it('lands its first value exactly at index period, one bar later than an Sma would', function () {
    $fixture = atrFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $period = $fixture['parameters']['period'];

    $result = (new Atr(period: $period))->compute($candles);

    expect($result[$period - 1])->toBeNull()
        ->and($result[$period])->not->toBeNull();
});

it('holds null for the whole warm-up, which is one bar longer than the period', function () {
    $candles = CandleFactory::fromCloses([1, 3, 2, 5, 4, 7, 6, 9]);

    $result = (new Atr(period: 5))->compute($candles);

    expect(array_slice($result, 0, 5))->each->toBeNull()
        ->and($result[5])->not->toBeNull();
});

it('takes each branch of the true range max in turn', function () {
    // period 1 makes the seed a mean of one TR and the Wilder step a no-op
    // ((prev * 0 + TR) / 1), so every value IS the bar's true range.
    $result = (new Atr(period: 1))->compute(atrBranchCandles());

    expect($result[0])->toBeNull()
        ->and((string) $result[1])->toBe('20.000000000000')   // |high - prevClose|
        ->and((string) $result[2])->toBe('31.000000000000')   // |low - prevClose|
        ->and((string) $result[3])->toBe('10.000000000000');  // high - low
});

it('seeds on the simple mean of the first period true ranges, then smooths', function () {
    $result = (new Atr(period: 2))->compute(atrBranchCandles());

    expect($result[0])->toBeNull()
        ->and($result[1])->toBeNull()
        // seed: (20 + 31) / 2
        ->and((string) $result[2])->toBe('25.500000000000')
        // Wilder: (25.5 * 1 + 10) / 2
        ->and((string) $result[3])->toBe('17.750000000000');
});

it('keeps every value at the policy scale', function (array $rows) {
    $result = (new Atr(period: 3))->compute(CandleFactory::fromRows($rows));
    $values = array_values(array_filter($result, static fn (?BigDecimal $value): bool => $value !== null));

    expect($values)->not->toBeEmpty();

    foreach ($values as $value) {
        expect($value->getScale())->toBe(Decimal::SCALE);
    }
})->with([
    'gaps in both directions' => [array_map(
        static fn (array $ohlc, int $index): array => [
            'openTime' => sprintf('2024-01-01T00:00:00+00:00 +%d days', $index),
            'open' => $ohlc[3], 'high' => $ohlc[0], 'low' => $ohlc[1], 'close' => $ohlc[2], 'volume' => '1',
        ],
        [['101', '99', '100', '100'], ['120', '118', '119', '119'], ['90', '88', '89', '89'], ['95', '85', '90', '90'], ['93', '91', '92', '92'], ['140', '139', '139.5', '139.5']],
        range(0, 5),
    )],
    'flat candles — every true range is zero' => [array_map(
        static fn (int $index): array => [
            'openTime' => sprintf('2024-01-01T00:00:00+00:00 +%d days', $index),
            'open' => '50', 'high' => '50', 'low' => '50', 'close' => '50', 'volume' => '1',
        ],
        range(0, 7),
    )],
]);

it('returns all nulls when there are fewer candles than the warm-up', function () {
    $candles = CandleFactory::fromCloses([10, 20, 15, 18, 17]);

    $result = (new Atr(period: 5))->compute($candles);

    expect($result)->toHaveCount(5)
        ->and($result)->each->toBeNull();
});

it('returns an empty series for empty input', function () {
    expect((new Atr(period: 14))->compute([]))->toBe([]);
});

it('always returns a series aligned with its input', function (int $period, int $length) {
    $candles = CandleFactory::fromCloses(range(1, $length));

    expect((new Atr(period: $period))->compute($candles))->toHaveCount($length);
})->with([
    [1, 1],
    [3, 3],
    [9, 4],
    [2, 20],
    [14, 200],
]);

it('rejects a period below 1 at construction', function (int $period) {
    expect(fn () => new Atr(period: $period))
        ->toThrow(InvalidParameterException::class);
})->with([0, -1, -14]);

it('names the offending class and value in the message', function () {
    expect(fn () => new Atr(period: 0))
        ->toThrow(InvalidParameterException::class, 'Baconfy\Indicators\Indicators\Atr requires a period >= 1, 0 given.');
});
