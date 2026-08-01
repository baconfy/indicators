<?php

declare(strict_types=1);

namespace Baconfy\Indicators;

use Baconfy\Indicators\Contracts\Indicator;
use Baconfy\Indicators\Exceptions\InvalidIndicatorException;
use Baconfy\Indicators\Exceptions\InvalidParameterException;
use Baconfy\Indicators\Exceptions\UnknownIndicatorException;
use Baconfy\Indicators\Indicators\Atr;
use Baconfy\Indicators\Indicators\Ema;
use Baconfy\Indicators\Indicators\Rsi;
use Baconfy\Indicators\Indicators\Sma;
use Error;

final class IndicatorManager
{
    /** @var array<string, class-string<Indicator>> */
    private array $indicators = [
        'sma' => Sma::class,
        'ema' => Ema::class,
        'rsi' => Rsi::class,
        'atr' => Atr::class,
    ];

    /**
     * @param  array<string, class-string<Indicator>>  $map  merged over the built-in defaults
     */
    public function __construct(array $map = [])
    {
        foreach ($map as $name => $indicatorClass) {
            $this->register($name, $indicatorClass);
        }
    }

    /**
     * @param  class-string<Indicator>  $indicatorClass
     */
    public function register(string $name, string $indicatorClass): void
    {
        $this->guardIsIndicator($indicatorClass);

        $this->indicators[$name] = $indicatorClass;
    }

    /**
     * @param  array<string, int|string|float>  $parameters  named-argument map, e.g. ['period' => 14]
     */
    public function make(string $name, array $parameters = []): Indicator
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

    private function guardIsIndicator(string $indicatorClass): void
    {
        if (! is_a($indicatorClass, Indicator::class, true)) {
            throw new InvalidIndicatorException(
                sprintf('%s must implement %s to be registered as an indicator.', $indicatorClass, Indicator::class),
            );
        }
    }
}
