<?php

declare(strict_types=1);

use Baconfy\Indicators\Attributes\AsIndicator;
use Baconfy\Indicators\Exceptions\InvalidIndicatorException;
use Baconfy\Indicators\Support\Discovery;
use Baconfy\Indicators\Tests\Support\DiscoveryFixtures\Duplicate\First;
use Baconfy\Indicators\Tests\Support\DiscoveryFixtures\Duplicate\Second;
use Baconfy\Indicators\Tests\Support\DiscoveryFixtures\Unnamed\Nameless;
use Baconfy\Indicators\Tests\Support\DiscoveryFixtures\Valid\FirstFile;
use Baconfy\Indicators\Tests\Support\DiscoveryFixtures\Valid\SecondFile;

function scanFixtures(string $directory): array
{
    return Discovery::scan(
        __DIR__."/../Support/DiscoveryFixtures/{$directory}",
        "Baconfy\\Indicators\\Tests\\Support\\DiscoveryFixtures\\{$directory}",
    );
}

it('maps each declared name to its class', function () {
    expect(scanFixtures('Valid'))->toBe([
        'first' => SecondFile::class,
        'second' => FirstFile::class,
    ]);
});

it('takes the name from the attribute, never from the file name', function () {
    $map = scanFixtures('Valid');

    expect(array_keys($map))->not->toContain('FirstFile', 'first-file', 'SecondFile');
});

it('orders the map by declared name, not by file name', function () {
    expect(array_keys(scanFixtures('Valid')))->toBe(['first', 'second']);
});

it('walks past a class implementing neither contract', function () {
    expect(scanFixtures('Valid'))->toHaveCount(2);
});

it('refuses a contract implementer that declares no name', function () {
    expect(fn () => scanFixtures('Unnamed'))
        ->toThrow(
            InvalidIndicatorException::class,
            sprintf('%s implements a contract but declares no %s name.', Nameless::class, AsIndicator::class),
        );
});

it('refuses two classes declaring the same name, naming both', function () {
    expect(fn () => scanFixtures('Duplicate'))
        ->toThrow(
            InvalidIndicatorException::class,
            sprintf('%s and %s both declare the indicator name "clash".', First::class, Second::class),
        );
});

it('returns an empty map for a directory that holds nothing to scan', function () {
    expect(Discovery::scan(__DIR__.'/../Fixtures', 'Baconfy\\Indicators\\Tests\\Fixtures'))->toBe([])
        ->and(Discovery::scan(__DIR__.'/../NoSuchDirectory', 'Baconfy\\Indicators\\Tests\\Nowhere'))->toBe([]);
});
