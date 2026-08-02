<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Support;

use Baconfy\Indicators\Attributes\AsIndicator;
use Baconfy\Indicators\Contracts\Indicator;
use Baconfy\Indicators\Contracts\MultiIndicator;
use Baconfy\Indicators\Exceptions\InvalidIndicatorException;
use ReflectionClass;

final class Discovery
{
    /**
     * @param  string  $namespace  the PSR-4 namespace $directory maps to
     * @return array<string, class-string<Indicator|MultiIndicator>> keyed by declared name, sorted by it
     *
     * @throws InvalidIndicatorException
     */
    public static function scan(string $directory, string $namespace): array
    {
        $map = [];

        foreach (glob(rtrim($directory, '/').'/*.php') ?: [] as $file) {
            $class = rtrim($namespace, '\\').'\\'.basename($file, '.php');

            if (! is_a($class, Indicator::class, true) && ! is_a($class, MultiIndicator::class, true)) {
                continue;
            }

            $name = self::declaredName($class);

            if (isset($map[$name])) {
                throw new InvalidIndicatorException(
                    sprintf('%s and %s both declare the indicator name "%s".', $map[$name], $class, $name),
                );
            }

            $map[$name] = $class;
        }

        ksort($map);

        return $map;
    }

    /**
     * @param  class-string<Indicator|MultiIndicator>  $class
     */
    private static function declaredName(string $class): string
    {
        $attributes = (new ReflectionClass($class))->getAttributes(AsIndicator::class);

        if ($attributes === []) {
            throw new InvalidIndicatorException(
                sprintf('%s implements a contract but declares no %s name.', $class, AsIndicator::class),
            );
        }

        return $attributes[0]->newInstance()->name;
    }
}
