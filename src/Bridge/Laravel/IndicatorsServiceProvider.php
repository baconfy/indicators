<?php

declare(strict_types=1);

namespace Baconfy\Indicators\Bridge\Laravel;

use Baconfy\Indicators\IndicatorManager;
use Illuminate\Support\ServiceProvider;

final class IndicatorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IndicatorManager::class);
    }
}
