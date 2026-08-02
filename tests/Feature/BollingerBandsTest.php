<?php

declare(strict_types=1);

use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Indicators\BollingerBands;
use Baconfy\Indicators\Indicators\Sma;
use Baconfy\Indicators\Math\Decimal;
use Baconfy\Indicators\Tests\Support\CandleFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

function bollingerFixture(): array
{
    return json_decode(
        file_get_contents(__DIR__.'/../Fixtures/bollinger-bands.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function bollingerFromFixture(array $fixture): BollingerBands
{
    return new BollingerBands(
        period: $fixture['parameters']['period'],
        multiplier: $fixture['parameters']['multiplier'],
    );
}

it('matches the golden fixture on every series at the reference precision', function (string $name) {
    $fixture = bollingerFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);
    $precision = $fixture['precision'];

    // The fixture only asserts the converged tail — its nulls mean "not asserted
    // by the reference", not "warm-up". Warm-up is pinned separately.
    $expected = array_filter($fixture['expected'][$name], static fn (?string $value): bool => $value !== null);

    expect($expected)->not->toBeEmpty();

    $computed = bollingerFromFixture($fixture)->compute($candles)[$name];

    $actual = [];

    foreach (array_keys($expected) as $index) {
        $actual[$index] = $computed[$index]?->toScale($precision, RoundingMode::HalfUp)->__toString();
    }

    expect($actual)->toBe($expected);
})->with(['basis', 'upper', 'lower']);

it('returns exactly the three named series of the contract', function () {
    $series = (new BollingerBands)->compute(CandleFactory::fromCloses(range(1, 25)));

    expect(array_keys($series))->toBe(['basis', 'upper', 'lower']);
});

it('is exactly the public sma on the basis series at every index', function (int $period) {
    $fixture = bollingerFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);

    $basis = (new BollingerBands(period: $period))->compute($candles)['basis'];
    $sma = (new Sma(period: $period))->compute($candles);

    expect($basis)->toHaveCount(count($sma));

    foreach ($basis as $index => $value) {
        if ($sma[$index] === null) {
            expect($value)->toBeNull();

            continue;
        }

        expect((string) $value)->toBe((string) $sma[$index]);
    }
})->with([20, 1, 7, 299]);

it('computes the population standard deviation of a hand-checkable window', function () {
    // closes mean 5, population variance (9+1+1+1+0+0+4+16)/8 = 4, stdev 2.
    $candles = CandleFactory::fromCloses([2, 4, 4, 4, 5, 5, 7, 9]);

    $series = (new BollingerBands(period: 8, multiplier: 2))->compute($candles);

    expect((string) $series['basis'][7])->toBe('5.000000000000')
        ->and((string) $series['upper'][7])->toBe('9.000000000000')
        ->and((string) $series['lower'][7])->toBe('1.000000000000');
});

it('collapses the three bands onto each other when the window is flat', function () {
    $candles = CandleFactory::fromCloses([1, 2, 3, 100, 100, 100, 100, 100]);

    $series = (new BollingerBands(period: 5))->compute($candles);

    expect((string) $series['basis'][7])->toBe('100.000000000000')
        ->and((string) $series['upper'][7])->toBe('100.000000000000')
        ->and((string) $series['lower'][7])->toBe('100.000000000000')
        // discriminating: the window one bar earlier still has a spread.
        ->and((string) $series['upper'][6])->not->toBe((string) $series['lower'][6]);
});

it('normalizes every accepted multiplier notation into the same series', function (BigDecimal|int|float|string $multiplier) {
    $candles = CandleFactory::fromRows(bollingerFixture()['candles']);

    $reference = (new BollingerBands(multiplier: '2.0'))->compute($candles);
    $series = (new BollingerBands(multiplier: $multiplier))->compute($candles);

    $stringify = static fn (array $values): array => array_map(
        static fn (?BigDecimal $value): ?string => $value?->__toString(),
        $values,
    );

    foreach (['basis', 'upper', 'lower'] as $name) {
        expect($stringify($series[$name]))->toBe($stringify($reference[$name]));
    }
})->with([
    '2.0',
    2,
    2.0,
    fn () => BigDecimal::of('2'),
]);

it('keeps every series aligned with its input', function (int $length) {
    $series = (new BollingerBands)->compute(CandleFactory::fromCloses(range(1, $length)));

    expect($series['basis'])->toHaveCount($length)
        ->and($series['upper'])->toHaveCount($length)
        ->and($series['lower'])->toHaveCount($length);
})->with([1, 5, 19, 20, 25, 299]);

it('yields three aligned all-null series when there is not enough data', function (int $length) {
    $series = (new BollingerBands)->compute(CandleFactory::fromCloses(range(1, $length)));

    foreach (['basis', 'upper', 'lower'] as $name) {
        expect($series[$name])->toHaveCount($length);

        foreach ($series[$name] as $value) {
            expect($value)->toBeNull();
        }
    }
})->with([1, 5, 19]);

it('returns three empty series for empty input', function () {
    expect((new BollingerBands)->compute([]))->toBe(['basis' => [], 'upper' => [], 'lower' => []]);
});

it('keeps every non-null value of every series at the policy scale', function () {
    $fixture = bollingerFixture();
    $candles = CandleFactory::fromRows($fixture['candles']);

    $series = bollingerFromFixture($fixture)->compute($candles);

    foreach (['basis', 'upper', 'lower'] as $name) {
        $values = array_values(array_filter($series[$name], static fn (?BigDecimal $value): bool => $value !== null));

        expect($values)->not->toBeEmpty();

        foreach ($values as $value) {
            expect($value->getScale())->toBe(Decimal::SCALE);
        }
    }
});

it('defaults to a period of 20 and a multiplier of 2', function () {
    $bands = new BollingerBands;

    expect($bands->period)->toBe(20)
        ->and($bands->multiplier)->toBeInstanceOf(BigDecimal::class)
        ->and($bands->multiplier->isEqualTo('2'))->toBeTrue();
});

it('rejects a period below 1 at construction', function (int $period) {
    expect(fn () => new BollingerBands(period: $period))->toThrow(InvalidParameterException::class);
})->with([0, -1, -20]);

it('rejects a multiplier that is not strictly positive', function (BigDecimal|int|float|string $multiplier) {
    expect(fn () => new BollingerBands(multiplier: $multiplier))->toThrow(InvalidParameterException::class);
})->with([
    0,
    '0',
    -1,
    '-2.0',
    fn () => BigDecimal::zero(),
]);

it('names the offending class and value in the period message', function () {
    expect(fn () => new BollingerBands(period: 0))
        ->toThrow(
            InvalidParameterException::class,
            'Baconfy\Indicators\Indicators\BollingerBands requires a period >= 1, 0 given.',
        );
});

it('names the offending class and value in the multiplier message', function () {
    expect(fn () => new BollingerBands(multiplier: '0'))
        ->toThrow(
            InvalidParameterException::class,
            'Baconfy\Indicators\Indicators\BollingerBands requires a multiplier > 0, 0 given.',
        )
        ->and(fn () => new BollingerBands(multiplier: '-2.0'))
        ->toThrow(
            InvalidParameterException::class,
            'Baconfy\Indicators\Indicators\BollingerBands requires a multiplier > 0, -2.0 given.',
        );
});
