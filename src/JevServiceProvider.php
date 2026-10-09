<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai;

use Illuminate\Support\ServiceProvider;

final class JevServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/jev.php', 'jev');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/jev.php' => config_path('jev.php'),
            ], 'jev-config');
        }
    }
}
