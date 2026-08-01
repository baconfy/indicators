<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Bridge\Laravel;

use Baconfy\Indicators\IndicatorManager;
use Illuminate\Support\ServiceProvider;

/**
 * The package's only Laravel-aware file, and its only import of Illuminate.
 *
 * It registers the manager and nothing else: indicators themselves are values
 * created per bot config at runtime, not container services (D9).
 */
final class IndicatorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IndicatorManager::class);
    }
}
