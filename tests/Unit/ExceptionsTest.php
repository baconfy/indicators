<?php

declare(strict_types=1);

use Baconfy\Indicators\Exceptions\IndicatorException;
use Baconfy\Indicators\Exceptions\InvalidIndicatorException;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Exceptions\UnknownIndicatorException;

dataset('concrete exceptions', [
    InvalidParameterException::class,
    InvalidIndicatorException::class,
    UnknownIndicatorException::class,
]);

it('marks every package exception with the package interface', function (string $exception) {
    expect(is_a($exception, IndicatorException::class, true))->toBeTrue();
})->with('concrete exceptions');

it('extends InvalidArgumentException', function (string $exception) {
    expect(is_subclass_of($exception, InvalidArgumentException::class))->toBeTrue();
})->with('concrete exceptions');

it('can be caught through the marker interface alone', function (string $exception) {
    $caught = null;

    try {
        throw new $exception('Sma requires a period >= 1, 0 given.');
    } catch (IndicatorException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf($exception)
        ->and($caught->getMessage())->toBe('Sma requires a period >= 1, 0 given.');
})->with('concrete exceptions');

it('declares IndicatorException as a plain marker interface', function () {
    $reflection = new ReflectionClass(IndicatorException::class);

    expect($reflection->isInterface())->toBeTrue()
        ->and($reflection->getMethods())->toBeEmpty();
});
