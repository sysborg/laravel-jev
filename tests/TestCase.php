<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sysborg\LaravelJevai\JevServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            JevServiceProvider::class,
        ];
    }
}
