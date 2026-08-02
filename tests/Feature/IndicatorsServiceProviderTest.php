<?php

declare(strict_types=1);

use Baconfy\Indicators\Bridge\Laravel\IndicatorsServiceProvider;
use Baconfy\Indicators\Contracts\Indicator;
use Baconfy\Indicators\IndicatorManager;
use Illuminate\Container\Container;

function containerWithProvider(): Container
{
    $container = new Container;

    (new IndicatorsServiceProvider($container))->register();

    return $container;
}

it('resolves the manager out of the container', function () {
    expect(containerWithProvider()->make(IndicatorManager::class))->toBeInstanceOf(IndicatorManager::class);
});

it('binds the manager as a singleton', function () {
    $container = containerWithProvider();

    expect($container->make(IndicatorManager::class))->toBe($container->make(IndicatorManager::class));
});

it('does not bind the indicator contract', function () {
    expect(containerWithProvider()->bound(Indicator::class))->toBeFalse();
});

it('leaves the indicators themselves out of the container', function (string $indicator) {
    expect(containerWithProvider()->bound($indicator))->toBeFalse();
})->with([
    Baconfy\Indicators\Indicators\Sma::class,
    Baconfy\Indicators\Indicators\Ema::class,
    Baconfy\Indicators\Indicators\Rsi::class,
    Baconfy\Indicators\Indicators\Atr::class,
    Baconfy\Indicators\Indicators\Rma::class,
    Baconfy\Indicators\Indicators\Obv::class,
    Baconfy\Indicators\Indicators\Vwma::class,
]);

it('hands out a manager that already knows the built-in indicators', function () {
    $manager = containerWithProvider()->make(IndicatorManager::class);

    expect($manager->available())
        ->toEqualCanonicalizing(['sma', 'ema', 'rsi', 'atr', 'rma', 'obv', 'vwma', 'macd', 'bollinger-bands', 'stochastic']);
});

it('keeps every boot concern out of the bridge', function () {
    $reflection = new ReflectionClass(IndicatorsServiceProvider::class);

    expect($reflection->hasMethod('boot'))->toBeFalse()
        ->and($reflection->isFinal())->toBeTrue();
});
