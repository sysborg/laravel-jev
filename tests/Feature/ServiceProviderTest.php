<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Adapters\Jev\HttpAccountGateway;
use Sysborg\LaravelJevai\Adapters\Jev\HttpDecisionGateway;
use Sysborg\LaravelJevai\Adapters\Jev\HttpWebContextGateway;
use Sysborg\LaravelJevai\Adapters\System\SystemClock;
use Sysborg\LaravelJevai\JevServiceProvider;
use Sysborg\LaravelJevai\Ports\Driven\AccountGateway;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\DecisionGateway;
use Sysborg\LaravelJevai\Ports\Driven\WebContextGateway;

it('registers the service provider', function () {
    expect(app()->getProviders(JevServiceProvider::class))->not->toBeEmpty();
});

it('merges the default configuration', function () {
    expect(config('jev.default'))->toBe('default')
        ->and(config('jev.connections.default.base_url'))->toBe('https://jev-ai.pro/api')
        ->and(config('jev.connections.default.model'))->toBe('jev-latest')
        ->and(config('jev.connections.default.timeout.connect'))->toBe(5)
        ->and(config('jev.connections.default.timeout.request'))->toBe(30);
});

it('reads the api key from the environment', function () {
    expect(config('jev.connections.default.api_key'))->toBe('test-key');
});

it('binds the driven ports to their adapters', function () {
    expect(app(Clock::class))->toBeInstanceOf(SystemClock::class)
        ->and(app(DecisionGateway::class))->toBeInstanceOf(HttpDecisionGateway::class)
        ->and(app(WebContextGateway::class))->toBeInstanceOf(HttpWebContextGateway::class)
        ->and(app(AccountGateway::class))->toBeInstanceOf(HttpAccountGateway::class);
});
