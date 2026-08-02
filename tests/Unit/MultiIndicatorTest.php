<?php

declare(strict_types=1);

use Baconfy\Indicators\Contracts\Indicator;
use Baconfy\Indicators\Contracts\MultiIndicator;
use Baconfy\Indicators\Tests\Support\CandleFactory;
use Baconfy\Indicators\Tests\Support\FakeMultiIndicator;

it('declares a single method named compute', function () {
    $reflection = new ReflectionClass(MultiIndicator::class);

    expect($reflection->isInterface())->toBeTrue()
        ->and($reflection->getMethods())->toHaveCount(1)
        ->and($reflection->getMethods()[0]->getName())->toBe('compute');
});

it('takes an array of candles and returns an array', function () {
    $compute = new ReflectionMethod(MultiIndicator::class, 'compute');

    expect($compute->getNumberOfParameters())->toBe(1)
        ->and($compute->getParameters()[0]->getName())->toBe('candles')
        ->and((string) $compute->getParameters()[0]->getType())->toBe('array')
        ->and((string) $compute->getReturnType())->toBe('array');
});

it('is a sibling of Indicator, extending nothing', function () {
    expect((new ReflectionClass(MultiIndicator::class))->getInterfaceNames())->toBe([])
        ->and(is_a(MultiIndicator::class, Indicator::class, true))->toBeFalse();
});

it('leaves Indicator untouched, not extending the multi contract either', function () {
    expect((new ReflectionClass(Indicator::class))->getInterfaceNames())->toBe([])
        ->and(is_a(Indicator::class, MultiIndicator::class, true))->toBeFalse();
});

it('returns named series, each one aligned to the input', function () {
    $candles = CandleFactory::fromCloses(['1', '2', '3', '4']);

    $series = (new FakeMultiIndicator)->compute($candles);

    expect(array_keys($series))->toBe(['first', 'second'])
        ->and($series['first'])->toHaveCount(4)
        ->and($series['second'])->toHaveCount(4);
});
