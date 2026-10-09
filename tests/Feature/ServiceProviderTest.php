<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\JevServiceProvider;

it('registers the service provider', function () {
    expect(app()->getProviders(JevServiceProvider::class))->not->toBeEmpty();
});

it('merges the default configuration', function () {
    expect(config('jev.base_url'))->toBe('https://jev-ai.pro/api')
        ->and(config('jev.default_model'))->toBe('jev-latest')
        ->and(config('jev.timeout.connect'))->toBe(5)
        ->and(config('jev.timeout.request'))->toBe(30);
});

it('reads the api key from the environment', function () {
    expect(config('jev.api_key'))->toBe('test-key');
});
