<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Tests;

use Illuminate\Foundation\Application;
use Laravel\Pulse\PulseServiceProvider;
use Livewire\LivewireServiceProvider;
use Sysborg\LaravelJevai\JevServiceProvider;

/**
 * Test case booting Livewire and Pulse next to the package, for the Pulse card.
 */
abstract class PulseTestCase extends TestCase
{
    /**
     * The providers to boot.
     *
     * @param  Application  $app  The application.
     * @return list<class-string> Provider classes.
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            PulseServiceProvider::class,
            JevServiceProvider::class,
        ];
    }

    /**
     * Use an in-memory SQLite database for Pulse storage.
     *
     * @param  Application  $app  The application.
     * @return void Nothing.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('jev.observability.metrics', 'pulse');
    }

    /**
     * Run Pulse's migrations.
     *
     * @return void Nothing.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/laravel/pulse/database/migrations');
    }
}
