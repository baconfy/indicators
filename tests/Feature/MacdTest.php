<?php

declare(strict_types=1);

use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Indicators\Ema;
use Baconfy\Indicators\Indicators\Macd;
use Baconfy\Indicators\Math\Decimal;
use Baconfy\Indicators\Tests\Support\CandleFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

function macdFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/../Fixtures/macd.json'), true, flags: JSON_THROW_ON_ERROR);
}

function macdFromFixture(array $fixture): Macd
{
    return new Macd(
        fast: $fixture['parameters']['fast'],
        slow: $fixture['parameters']['slow'],
        signal: $fixture['parameters']['signal'],
    );
}

it('matches the golden fixture on every series at the reference precision', function (string $name) {
    $fixture = macdFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $precision = $fixture['precision'];

    // The fixture only asserts the converged tail — its nulls mean "not asserted
    // by the reference", not "warm-up". Warm-up is pinned separately.
    $expected = array_filter($fixture['expected'][$name], static fn (?string $value): bool => $value !== null);

    expect($expected)->not->toBeEmpty();

    $computed = macdFromFixture($fixture)->compute($candles)[$name];

    $actual = [];

    foreach (array_keys($expected) as $index) {
        $actual[$index] = $computed[$index]?->toScale($precision, RoundingMode::HalfUp)->__toString();
    }

    expect($actual)->toBe($expected);
})->with(['macd', 'signal', 'histogram']);

it('returns exactly the three named series of the contract', function () {
    $series = (new Macd)->compute(CandleFactory::fromCloses(range(1, 40)));

    expect(array_keys($series))->toBe(['macd', 'signal', 'histogram']);
});

it('lands the macd line at slow - 1 and the signal at slow + signal - 2', function (int $fast, int $slow, int $signal) {
    $candles = CandleFactory::fromCloses(range(1, 120));

    $series = (new Macd(fast: $fast, slow: $slow, signal: $signal))->compute($candles);

    $firstNonNull = static function (array $values): ?int {
        foreach ($values as $index => $value) {
            if ($value !== null) {
                return $index;
            }
        }

        return null;
    };

    expect($firstNonNull($series['macd']))->toBe($slow - 1)
        ->and($firstNonNull($series['signal']))->toBe($slow + $signal - 2)
        ->and($firstNonNull($series['histogram']))->toBe($slow + $signal - 2);
})->with([
    [12, 26, 9],
    [1, 2, 1],
    [3, 7, 4],
    [5, 35, 20],
]);

it('is exactly the difference of the two public emas at every overlapping index', function (int $fast, int $slow) {
    $fixture = macdFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);

    $macd = (new Macd(fast: $fast, slow: $slow))->compute($candles)['macd'];
    $emaFast = (new Ema(period: $fast))->compute($candles);
    $emaSlow = (new Ema(period: $slow))->compute($candles);

    $overlapping = 0;

    foreach ($macd as $index => $value) {
        if ($emaFast[$index] === null || $emaSlow[$index] === null) {
            expect($value)->toBeNull();

            continue;
        }

        $overlapping++;

        expect((string) $value)->toBe((string) $emaFast[$index]->minus($emaSlow[$index]));
    }

    expect($overlapping)->toBe(count($candles) - $slow + 1);
})->with([
    [12, 26],
    [3, 10],
    [1, 2],
]);

it('keeps every series aligned with its input', function (int $length) {
    $candles = CandleFactory::fromCloses(range(1, $length));

    $series = (new Macd)->compute($candles);

    expect($series['macd'])->toHaveCount($length)
        ->and($series['signal'])->toHaveCount($length)
        ->and($series['histogram'])->toHaveCount($length);
})->with([1, 5, 25, 33, 40, 299]);

it('yields three aligned all-null series when there is not enough data', function (int $length) {
    $series = (new Macd)->compute(CandleFactory::fromCloses(range(1, $length)));

    foreach (['macd', 'signal', 'histogram'] as $name) {
        expect($series[$name])->toHaveCount($length);

        foreach ($series[$name] as $value) {
            expect($value)->toBeNull();
        }
    }
})->with([1, 5, 25]);

it('returns three empty series for empty input', function () {
    expect((new Macd)->compute([]))->toBe(['macd' => [], 'signal' => [], 'histogram' => []]);
});

it('keeps every non-null value of every series at the policy scale', function () {
    $fixture = macdFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);

    $series = macdFromFixture($fixture)->compute($candles);

    foreach (['macd', 'signal', 'histogram'] as $name) {
        $values = array_values(array_filter($series[$name], static fn (?BigDecimal $value): bool => $value !== null));

        expect($values)->not->toBeEmpty();

        foreach ($values as $value) {
            expect($value->getScale())->toBe(Decimal::SCALE);
        }
    }
});

it('defaults to the 12 / 26 / 9 parameters', function () {
    $macd = new Macd;

    expect($macd->fast)->toBe(12)
        ->and($macd->slow)->toBe(26)
        ->and($macd->signal)->toBe(9);
});

it('rejects a fast period below 1 at construction', function (int $fast) {
    expect(fn () => new Macd(fast: $fast))->toThrow(InvalidParameterException::class);
})->with([0, -1, -12]);

it('rejects a slow period below 1 at construction', function (int $slow) {
    expect(fn () => new Macd(fast: 1, slow: $slow))->toThrow(InvalidParameterException::class);
})->with([0, -1, -26]);

it('rejects a signal period below 1 at construction', function (int $signal) {
    expect(fn () => new Macd(signal: $signal))->toThrow(InvalidParameterException::class);
})->with([0, -1, -9]);

it('rejects a slow period that does not exceed the fast one', function (int $fast, int $slow) {
    expect(fn () => new Macd(fast: $fast, slow: $slow))->toThrow(InvalidParameterException::class);
})->with([
    [26, 26],
    [26, 12],
    [2, 1],
]);

it('names the offending class and value in the period message', function () {
    expect(fn () => new Macd(fast: 0))
        ->toThrow(InvalidParameterException::class, 'Baconfy\Indicators\Indicators\Macd requires a fast >= 1, 0 given.')
        ->and(fn () => new Macd(slow: 0))
        ->toThrow(InvalidParameterException::class, 'Baconfy\Indicators\Indicators\Macd requires a slow >= 1, 0 given.')
        ->and(fn () => new Macd(signal: 0))
        ->toThrow(InvalidParameterException::class, 'Baconfy\Indicators\Indicators\Macd requires a signal >= 1, 0 given.');
});

it('names the offending class and both periods in the ordering message', function () {
    expect(fn () => new Macd(fast: 26, slow: 12))
        ->toThrow(
            InvalidParameterException::class,
            'Baconfy\Indicators\Indicators\Macd requires a slow period greater than the fast one, fast 26 and slow 12 given.',
        );
});
