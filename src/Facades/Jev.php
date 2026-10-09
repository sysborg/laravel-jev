<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Facades;

use Illuminate\Support\Facades\Facade;
use Sysborg\LaravelJevai\Application\JevClient;
use Sysborg\LaravelJevai\Ports\Driving\Jev as JevContract;

/**
 * Static access to the Jev client.
 *
 * @method static \Sysborg\LaravelJevai\Domain\Decision\DecisionResult decide(\Sysborg\LaravelJevai\Domain\Decision\DecisionRequest $request)
 * @method static \Sysborg\LaravelJevai\Domain\Decision\PendingDecision queue(\Sysborg\LaravelJevai\Domain\Decision\DecisionRequest $request)
 * @method static \Sysborg\LaravelJevai\Domain\WebContext\WebContextResult resolveWebContext(\Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest $request)
 * @method static list<string> models()
 * @method static \Sysborg\LaravelJevai\Domain\Account\Balance balance()
 * @method static \Sysborg\LaravelJevai\Application\Builder\DecisionBuilder request()
 * @method static \Sysborg\LaravelJevai\Application\Builder\DecisionBuilder model(string $model)
 * @method static \Sysborg\LaravelJevai\Application\Builder\DecisionBuilder state(string|array<array-key, mixed> $state)
 * @method static \Sysborg\LaravelJevai\Application\Builder\DecisionBuilder judge(string $judgeId, ?int $revision = null)
 * @method static \Sysborg\LaravelJevai\Application\Builder\WebContextBuilder webContext(string $question)
 *
 * @see JevClient
 */
final class Jev extends Facade
{
    /**
     * The container binding the facade resolves.
     *
     * Example:
     * ```php
     * Jev::getFacadeRoot(); // JevClient
     * ```
     *
     * @return string The driving port interface.
     */
    protected static function getFacadeAccessor(): string
    {
        return JevContract::class;
    }
}
