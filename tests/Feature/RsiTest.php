<?php

declare(strict_types=1);

use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Indicators\Rsi;
use Baconfy\Indicators\Math\Decimal;
use Baconfy\Indicators\Tests\Support\CandleFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

function rsiFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/rsi.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The fixture only asserts the converged tail — its nulls mean "not asserted by
 * the reference", not "warm-up". Warm-up is pinned separately, on synthetic data.
 *
 * @return array<int, string>
 */
function rsiAssertedValues(array $fixture): array
{
    return array_filter($fixture['expected'], static fn (?string $value): bool => $value !== null);
}

it('matches the golden fixture at the reference precision', function () {
    $fixture = rsiFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $precision = $fixture['precision'];
    $expected = rsiAssertedValues($fixture);

    expect($expected)->not->toBeEmpty();

    $computed = (new Rsi(period: $fixture['parameters']['period']))->compute($candles);

    $actual = [];

    foreach (array_keys($expected) as $index) {
        $actual[$index] = $computed[$index]?->toScale($precision, RoundingMode::HalfUp)->__toString();
    }

    expect($actual)->toBe($expected);
});

it('lands its first value exactly at index period, one bar later than an Sma would', function () {
    $fixture = rsiFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $period = $fixture['parameters']['period'];

    $result = (new Rsi(period: $period))->compute($candles);

    expect($result[$period - 1])->toBeNull()
        ->and($result[$period])->not->toBeNull();
});

it('holds null for the whole warm-up, which is one bar longer than the period', function () {
    $candles = CandleFactory::fromCloses([1, 3, 2, 5, 4, 7, 6, 9]);

    $result = (new Rsi(period: 5))->compute($candles);

    expect(array_slice($result, 0, 5))->each->toBeNull()
        ->and($result[5])->not->toBeNull();
});

it('yields exactly 100 once there are no losses in the window', function () {
    $candles = CandleFactory::fromCloses(range(1, 30));

    $result = (new Rsi(period: 14))->compute($candles);

    foreach (array_slice($result, 14, preserve_keys: true) as $value) {
        expect((string) $value)->toBe('100.000000000000');
    }
});

it('yields exactly 0 once there are no gains in the window', function () {
    $candles = CandleFactory::fromCloses(range(30, 1));

    $result = (new Rsi(period: 14))->compute($candles);

    foreach (array_slice($result, 14, preserve_keys: true) as $value) {
        expect((string) $value)->toBe('0.000000000000');
    }
});

it('keeps every value at the policy scale, edge cases included', function (array $closes) {
    $candles = CandleFactory::fromCloses($closes);

    $result = (new Rsi(period: 5))->compute($candles);
    $values = array_values(array_filter($result, static fn (?BigDecimal $value): bool => $value !== null));

    expect($values)->not->toBeEmpty();

    foreach ($values as $value) {
        expect($value->getScale())->toBe(Decimal::SCALE);
    }
})->with([
    'mixed' => [[3, 1, 4, 1, 5, 9, 2, 6, 5, 3, 5, 8, 9, 7, 9, 3, 2, 3, 8, 4]],
    'strictly rising, then falling — passes through the avgLoss == 0 branch' => [[1, 2, 3, 4, 5, 6, 7, 8, 7, 6, 5, 4, 3, 2, 1]],
    'strictly falling, then rising — passes through the avgGain == 0 branch' => [[15, 14, 13, 12, 11, 10, 9, 8, 9, 10, 11, 12, 13, 14, 15]],
]);

it('returns all nulls when there are fewer candles than the warm-up', function () {
    $candles = CandleFactory::fromCloses([10, 20, 15, 18, 17]);

    $result = (new Rsi(period: 5))->compute($candles);

    expect($result)->toHaveCount(5)
        ->and($result)->each->toBeNull();
});

it('returns an empty series for empty input', function () {
    expect((new Rsi(period: 14))->compute([]))->toBe([]);
});

it('always returns a series aligned with its input', function (int $period, int $length) {
    $candles = CandleFactory::fromCloses(range(1, $length));

    expect((new Rsi(period: $period))->compute($candles))->toHaveCount($length);
})->with([
    [1, 1],
    [3, 3],
    [9, 4],
    [2, 20],
    [14, 200],
]);

it('rejects a period below 1 at construction', function (int $period) {
    expect(fn () => new Rsi(period: $period))
        ->toThrow(InvalidParameterException::class);
})->with([0, -1, -14]);

it('names the offending class and value in the message', function () {
    expect(fn () => new Rsi(period: 0))
        ->toThrow(InvalidParameterException::class, 'Baconfy\Indicators\Indicators\Rsi requires a period >= 1, 0 given.');
});
