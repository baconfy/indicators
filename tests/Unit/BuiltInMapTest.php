<?php

declare(strict_types=1);

use Baconfy\Indicators\Contracts\Indicator;
use Baconfy\Indicators\Contracts\MultiIndicator;
use Baconfy\Indicators\IndicatorManager;

/**
 * Every class in src/Indicators/ implementing one of the two contracts.
 *
 * @return list<class-string<Indicator|MultiIndicator>>
 */
function scannedIndicatorClasses(): array
{
    $classes = [];

    foreach (glob(__DIR__.'/../../src/Indicators/*.php') as $file) {
        $class = 'Baconfy\\Indicators\\Indicators\\'.basename($file, '.php');

        if (is_a($class, Indicator::class, true) || is_a($class, MultiIndicator::class, true)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/**
 * Every class the built-in map resolves to, through make() with full defaults.
 *
 * @return list<class-string<Indicator|MultiIndicator>>
 */
function mappedIndicatorClasses(): array
{
    $manager = new IndicatorManager;

    $classes = array_map(
        fn (string $name): string => $manager->make($name)::class,
        $manager->available(),
    );

    sort($classes);

    return $classes;
}

it('registers every indicator that exists in src/Indicators', function () {
    $missing = array_diff(scannedIndicatorClasses(), mappedIndicatorClasses());

    expect($missing)->toBe([], implode(
        ', ',
        array_map(fn (string $class): string => "{$class} is not registered", $missing),
    ));
});

it('maps no indicator that does not exist in src/Indicators', function () {
    $orphans = array_diff(mappedIndicatorClasses(), scannedIndicatorClasses());

    expect($orphans)->toBe([], implode(
        ', ',
        array_map(fn (string $class): string => "{$class} is mapped but does not exist there", $orphans),
    ));
});

it('gives each indicator exactly one name, aliases included', function () {
    $names = (new IndicatorManager)->available();

    expect(count($names))->toBe(
        count(scannedIndicatorClasses()),
        sprintf(
            'The map has %d names for %d indicator classes: %s',
            count($names),
            count(scannedIndicatorClasses()),
            implode(', ', $names),
        ),
    );
});
