<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Sysborg\LaravelJevai\Adapters\Jev\ConnectionRegistry;
use Sysborg\LaravelJevai\Adapters\Jev\GatewayFactory;
use Sysborg\LaravelJevai\Adapters\System\SystemClock;
use Sysborg\LaravelJevai\Ports\Driven\AccountGateway;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\DecisionGateway;
use Sysborg\LaravelJevai\Ports\Driven\WebContextGateway;

/**
 * Registers the package configuration and binds the ports to their adapters.
 */
final class JevServiceProvider extends ServiceProvider
{
    /**
     * Merge the package defaults into the `jev` config key and bind the ports.
     *
     * Values in the application's published `config/jev.php` take precedence.
     * Gateways resolve the default connection; connections are only validated
     * when first used, so a missing API key never breaks application boot.
     *
     * Example:
     * ```php
     * app(DecisionGateway::class); // HttpDecisionGateway for jev.default
     * ```
     *
     * @return void Nothing; bindings are registered on the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/jev.php', 'jev');

        $this->app->singleton(Clock::class, SystemClock::class);

        $this->app->singleton(ConnectionRegistry::class, function (Container $app): ConnectionRegistry {
            $config = $app->make(Config::class);
            $connections = $config->get('jev.connections', []);
            $default = $config->get('jev.default', 'default');

            return new ConnectionRegistry(
                is_array($connections) ? $connections : [],
                is_string($default) ? $default : 'default',
                $app instanceof Application && $app->environment('testing'),
            );
        });

        $this->app->singleton(GatewayFactory::class);

        $this->app->bind(DecisionGateway::class, fn (Container $app) => $app->make(GatewayFactory::class)->decision());
        $this->app->bind(WebContextGateway::class, fn (Container $app) => $app->make(GatewayFactory::class)->webContext());
        $this->app->bind(AccountGateway::class, fn (Container $app) => $app->make(GatewayFactory::class)->account());
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
