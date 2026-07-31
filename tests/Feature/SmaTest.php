<?php

declare(strict_types=1);

use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Indicators\Sma;
use Baconfy\Indicators\Tests\Support\CandleFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

function smaFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/sma.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('matches the golden fixture at the reference precision', function () {
    $fixture = smaFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $precision = $fixture['precision'];

    $computed = (new Sma(period: $fixture['parameters']['period']))->compute($candles);

    $actual = array_map(
        static fn (?BigDecimal $value): ?string => $value?->toScale($precision, RoundingMode::HalfUp)->__toString(),
        $computed,
    );

    expect($actual)->toBe($fixture['expected']);
});

it('holds null for the whole warm-up and yields the first value at period - 1', function () {
    $candles = CandleFactory::fromCloses([1, 2, 3, 4, 5, 6, 7, 8]);

    $result = (new Sma(period: 5))->compute($candles);

    expect(array_slice($result, 0, 4))->each->toBeNull()
        ->and($result[4])->not->toBeNull()
        ->and((string) $result[4])->toBe('3.000000000000');
});

it('returns all nulls when there are fewer candles than the period', function () {
    $candles = CandleFactory::fromCloses([10, 20]);

    $result = (new Sma(period: 5))->compute($candles);

    expect($result)->toHaveCount(2)
        ->and($result)->each->toBeNull();
});

it('returns an empty series for empty input', function () {
    expect((new Sma(period: 5))->compute([]))->toBe([]);
});

it('always returns a series aligned with its input', function (int $period, int $length) {
    $candles = CandleFactory::fromCloses(range(1, $length));

    expect((new Sma(period: $period))->compute($candles))->toHaveCount($length);
})->with([
    [1, 1],
    [3, 3],
    [9, 4],
    [2, 20],
    [14, 200],
]);

it('divides through the package math policy', function () {
    $candles = CandleFactory::fromCloses([1, 2, 4]);

    $result = (new Sma(period: 3))->compute($candles);

    expect((string) $result[2])->toBe('2.333333333333');
});

it('rejects a period below 1 at construction', function (int $period) {
    expect(fn () => new Sma(period: $period))
        ->toThrow(InvalidParameterException::class);
})->with([0, -1, -14]);

it('names the offending class and value in the message', function () {
    expect(fn () => new Sma(period: 0))
        ->toThrow(InvalidParameterException::class, 'Baconfy\Indicators\Indicators\Sma requires a period >= 1, 0 given.');
});
