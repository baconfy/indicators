<?php

declare(strict_types=1);

namespace Baconfy\Indicators;

use Baconfy\Indicators\Contracts\Indicator;
use Baconfy\Indicators\Contracts\MultiIndicator;
use Baconfy\Indicators\Exceptions\InvalidIndicatorException;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Exceptions\UnknownIndicatorException;
use Baconfy\Indicators\Support\Discovery;
use Error;

final class IndicatorManager
{
    /**
     * The built-in map, discovered once per process.
     *
     * @var array<string, class-string<Indicator|MultiIndicator>>|null
     */
    private static ?array $builtIn = null;

    /** @var array<string, class-string<Indicator|MultiIndicator>> */
    private array $indicators;

    /**
     * @param  array<string, class-string<Indicator|MultiIndicator>>  $map  merged over the built-in defaults
     */
    public function __construct(array $map = [])
    {
        $this->indicators = self::builtIn();

        foreach ($map as $name => $indicatorClass) {
            $this->register($name, $indicatorClass);
        }
    }

    /**
     * @param  class-string<Indicator|MultiIndicator>  $indicatorClass
     */
    public function register(string $name, string $indicatorClass): void
    {
        $this->guardIsIndicator($indicatorClass);

        $this->indicators[$name] = $indicatorClass;
    }

    /**
     * @param  array<string, int|string|float>  $parameters  named-argument map, e.g. ['period' => 14]
     */
    public function make(string $name, array $parameters = []): Indicator|MultiIndicator
    {
        $indicatorClass = $this->indicators[$name] ?? throw new UnknownIndicatorException(
            sprintf('Unknown indicator "%s". Available: %s.', $name, implode(', ', $this->available())),
        );

        try {
            return new $indicatorClass(...$parameters);
        } catch (Error $e) {
            throw new InvalidParameterException(
                sprintf(
                    '%s cannot be created with the given parameters (%s): %s',
                    $indicatorClass,
                    implode(', ', array_keys($parameters)),
                    $e->getMessage(),
                ),
                previous: $e,
            );
        }
    }

    /**
     * @return list<string>
     */
    public function available(): array
    {
        return array_keys($this->indicators);
    }

    /**
     * The built-in names are declared by attribute on the classes themselves and
     * resolved lazily: the filesystem is touched once per process, never per
     * manager instance (D13).
     *
     * @return array<string, class-string<Indicator|MultiIndicator>>
     */
    private static function builtIn(): array
    {
        return self::$builtIn ??= Discovery::scan(__DIR__.'/Indicators', __NAMESPACE__.'\\Indicators');
    }

    private function guardIsIndicator(string $indicatorClass): void
    {
        if (is_a($indicatorClass, Indicator::class, true) || is_a($indicatorClass, MultiIndicator::class, true)) {
            return;
        }

        throw new InvalidIndicatorException(
            sprintf(
                '%s must implement %s or %s to be registered as an indicator.',
                $indicatorClass,
                Indicator::class,
                MultiIndicator::class,
            ),
        );
    }
}
