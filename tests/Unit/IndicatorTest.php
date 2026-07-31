<?php

declare(strict_types=1);

use Baconfy\Indicators\Contracts\Indicator;

it('declares a single method named compute', function () {
    $reflection = new ReflectionClass(Indicator::class);

    expect($reflection->isInterface())->toBeTrue()
        ->and($reflection->getMethods())->toHaveCount(1)
        ->and($reflection->getMethods()[0]->getName())->toBe('compute');
});

it('takes an array of candles and returns an array', function () {
    $compute = new ReflectionMethod(Indicator::class, 'compute');

    expect($compute->getNumberOfParameters())->toBe(1)
        ->and($compute->getParameters()[0]->getName())->toBe('candles')
        ->and((string) $compute->getParameters()[0]->getType())->toBe('array')
        ->and((string) $compute->getReturnType())->toBe('array');
});
