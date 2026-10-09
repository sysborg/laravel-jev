<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sysborg\LaravelJevai\Application\Pipeline\Pipeline;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Budget;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Protect;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Retry;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Trace;
use Sysborg\LaravelJevai\Domain\Exceptions\BudgetExceeded;
use Sysborg\LaravelJevai\Domain\Exceptions\LocallyRateLimited;
use Sysborg\LaravelJevai\Facades\Jev;

beforeEach(function () {
    config()->set('cache.default', 'array');
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(jevFixture('decision-success'))]);
});

it('runs the budget outside and the protection inside the retries', function () {
    $stages = array_map(fn ($stage) => $stage::class, app(Pipeline::class)->stages());

    expect(array_slice($stages, -4))->toBe([Budget::class, Trace::class, Retry::class, Protect::class]);
});

it('enforces the per-minute rate limit through the cache', function () {
    config()->set('jev.limits.rate_limit_per_minute', '2');

    Jev::state('text')->noul('q', 'Q?')->evaluate();
    Jev::state('text')->noul('q', 'Q?')->evaluate();

    expect(fn () => Jev::state('text')->noul('q', 'Q?')->evaluate())->toThrow(LocallyRateLimited::class);
    Http::assertSentCount(2);
});

it('enforces the daily budget through the cache', function () {
    config()->set('jev.limits.daily_input_tokens', '100');

    Jev::state('text')->noul('q', 'Q?')->evaluate();

    expect(fn () => Jev::state('text')->noul('q', 'Q?')->evaluate())->toThrow(BudgetExceeded::class);
    Http::assertSentCount(1);
});
