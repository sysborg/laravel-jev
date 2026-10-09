<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the package configuration and, as adapters land, the port bindings.
 */
final class JevServiceProvider extends ServiceProvider
{
    /**
     * Merge the package defaults into the `jev` config key.
     *
     * Values in the application's published `config/jev.php` take precedence.
     *
     * Example:
     * ```php
     * config('jev.default_model'); // 'jev-latest' unless overridden
     * ```
     *
     * @return void Nothing; bindings are registered on the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/jev.php', 'jev');
    }

    /**
     * Make the config publishable when running in the console.
     *
     * Example:
     * ```bash
     * php artisan vendor:publish --tag=jev-config
     * ```
     *
     * @return void Nothing; publishable paths are registered.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/jev.php' => config_path('jev.php'),
            ], 'jev-config');
        }
    }
}
