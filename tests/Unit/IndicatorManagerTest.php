<?php

declare(strict_types=1);

use Baconfy\Indicators\Contracts\Indicator;
use Baconfy\Indicators\Contracts\MultiIndicator;
use Baconfy\Indicators\Exceptions\InvalidIndicatorException;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Exceptions\UnknownIndicatorException;
use Baconfy\Indicators\IndicatorManager;
use Baconfy\Indicators\Indicators\Atr;
use Baconfy\Indicators\Indicators\Ema;
use Baconfy\Indicators\Indicators\Macd;
use Baconfy\Indicators\Indicators\Obv;
use Baconfy\Indicators\Indicators\Rma;
use Baconfy\Indicators\Indicators\Rsi;
use Baconfy\Indicators\Indicators\Sma;
use Baconfy\Indicators\Indicators\Vwma;
use Baconfy\Indicators\Tests\Support\FakeIndicator;
use Baconfy\Indicators\Tests\Support\FakeMultiIndicator;

dataset('built-in indicators', [
    ['sma', Sma::class],
    ['ema', Ema::class],
    ['rsi', Rsi::class],
    ['atr', Atr::class],
    ['rma', Rma::class],
    ['obv', Obv::class],
    ['vwma', Vwma::class],
    ['macd', Macd::class],
]);

it('resolves every built-in name to its indicator', function (string $name, string $class) {
    expect((new IndicatorManager)->make($name))->toBeInstanceOf($class);
})->with('built-in indicators');

it('spreads parameters as named arguments', function () {
    $indicator = (new IndicatorManager)->make('rsi', ['period' => 21]);

    expect($indicator)->toBeInstanceOf(Rsi::class)
        ->and($indicator->period)->toBe(21);
});

it('makes parameters optional, falling back to the indicator defaults', function () {
    $indicator = (new IndicatorManager)->make('rsi');

    expect($indicator->period)->toBe((new Rsi)->period);
});

it('rejects an unknown name naming it and the available ones', function () {
    expect(fn () => (new IndicatorManager)->make('ichimoku'))
        ->toThrow(UnknownIndicatorException::class)
        ->and(fn () => (new IndicatorManager)->make('ichimoku'))
        ->toThrow(UnknownIndicatorException::class, 'ichimoku');
});

it('lists the available names in the unknown name message', function () {
    try {
        (new IndicatorManager)->make('ichimoku');
    } catch (UnknownIndicatorException $e) {
        expect($e->getMessage())->toContain('sma', 'ema', 'rsi', 'atr', 'rma', 'obv', 'vwma', 'macd');
    }
});

it('wraps an unknown parameter name into a package exception', function () {
    expect(fn () => (new IndicatorManager)->make('rsi', ['lenght' => 14]))
        ->toThrow(InvalidParameterException::class, Rsi::class);
});

it('lets the indicator own validation error through unwrapped', function () {
    $thrown = null;

    try {
        (new IndicatorManager)->make('sma', ['period' => 0]);
    } catch (InvalidParameterException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(InvalidParameterException::class)
        ->and($thrown->getMessage())->toBe(sprintf('%s requires a period >= 1, 0 given.', Sma::class))
        ->and($thrown->getPrevious())->toBeNull();
});

it('registers and resolves a custom indicator', function () {
    $manager = new IndicatorManager;
    $manager->register('fake', FakeIndicator::class);

    expect($manager->make('fake', ['period' => 5]))->toBeInstanceOf(FakeIndicator::class)
        ->and($manager->available())->toContain('fake');
});

it('lists the built-in names as available', function () {
    expect((new IndicatorManager)->available())
        ->toEqualCanonicalizing(['sma', 'ema', 'rsi', 'atr', 'rma', 'obv', 'vwma', 'macd']);
});

it('resolves the parameterless obv without any parameters at all', function () {
    expect((new IndicatorManager)->make('obv'))->toBeInstanceOf(Obv::class);
});

it('wraps a parameter handed to the parameterless obv into a package exception', function () {
    expect(fn () => (new IndicatorManager)->make('obv', ['period' => 14]))
        ->toThrow(InvalidParameterException::class, Obv::class);
});

it('refuses to register a class that is neither an indicator nor a multi indicator', function () {
    expect(fn () => (new IndicatorManager)->register('bad', stdClass::class))
        ->toThrow(
            InvalidIndicatorException::class,
            sprintf(
                '%s must implement %s or %s to be registered as an indicator.',
                stdClass::class,
                Indicator::class,
                MultiIndicator::class,
            ),
        );
});

it('refuses a constructor supplied map entry that is neither an indicator nor a multi indicator', function () {
    expect(fn () => new IndicatorManager(map: ['bad' => stdClass::class]))
        ->toThrow(
            InvalidIndicatorException::class,
            sprintf(
                '%s must implement %s or %s to be registered as an indicator.',
                stdClass::class,
                Indicator::class,
                MultiIndicator::class,
            ),
        );
});

it('names both acceptable interfaces in the guard message', function () {
    try {
        (new IndicatorManager)->register('bad', stdClass::class);
    } catch (InvalidIndicatorException $e) {
        expect($e->getMessage())->toContain(Indicator::class)
            ->and($e->getMessage())->toContain(MultiIndicator::class);
    }
});

it('accepts a constructor supplied map entry that is an indicator', function () {
    $manager = new IndicatorManager(map: ['fake' => FakeIndicator::class]);

    expect($manager->make('fake'))->toBeInstanceOf(FakeIndicator::class)
        ->and($manager->available())->toContain('fake', 'sma');
});

it('registers and resolves a multi indicator', function () {
    $manager = new IndicatorManager;
    $manager->register('fake_multi', FakeMultiIndicator::class);

    expect($manager->make('fake_multi', ['period' => 5]))->toBeInstanceOf(MultiIndicator::class)
        ->and($manager->make('fake_multi'))->toBeInstanceOf(FakeMultiIndicator::class)
        ->and($manager->available())->toContain('fake_multi');
});

it('accepts a constructor supplied map entry that is a multi indicator', function () {
    $manager = new IndicatorManager(map: ['fake_multi' => FakeMultiIndicator::class]);

    expect($manager->make('fake_multi'))->toBeInstanceOf(MultiIndicator::class)
        ->and($manager->available())->toContain('fake_multi', 'sma');
});

it('keeps the two contracts apart when resolving', function () {
    $manager = new IndicatorManager(map: [
        'fake' => FakeIndicator::class,
        'fake_multi' => FakeMultiIndicator::class,
    ]);

    expect($manager->make('fake'))->toBeInstanceOf(Indicator::class)
        ->and($manager->make('fake'))->not->toBeInstanceOf(MultiIndicator::class)
        ->and($manager->make('fake_multi'))->toBeInstanceOf(MultiIndicator::class)
        ->and($manager->make('fake_multi'))->not->toBeInstanceOf(Indicator::class);
});

it('returns a new instance per call', function () {
    $manager = new IndicatorManager;

    expect($manager->make('ema', ['period' => 9]))->not->toBe($manager->make('ema', ['period' => 9]));
});
