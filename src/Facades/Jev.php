<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Facades;

use Illuminate\Support\Facades\Facade;
use RuntimeException;
use Sysborg\LaravelJevai\Adapters\Testing\JevFake;
use Sysborg\LaravelJevai\Application\JevClient;
use Sysborg\LaravelJevai\Ports\Driven\AccountGateway;
use Sysborg\LaravelJevai\Ports\Driven\DecisionGateway;
use Sysborg\LaravelJevai\Ports\Driven\DecisionQueue;
use Sysborg\LaravelJevai\Ports\Driven\WebContextGateway;
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
 * @method static void assertEvaluated(\Closure|null $matching = null)
 * @method static void assertNotEvaluated(\Closure|null $matching = null)
 * @method static void assertNothingEvaluated()
 * @method static void assertEvaluatedTimes(int $times)
 * @method static void assertJudgeUsed(string $judgeId, ?int $revision = null)
 * @method static void assertTokensUsedLessThan(int $limit)
 * @method static void assertWebContextResolved(\Closure|null $matching = null)
 * @method static void assertQueued(\Closure|null $matching = null)
 *
 * @see JevClient
 *
 * @api
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

    /**
     * Replace Jev with an in-memory fake for the rest of the test.
     *
     * Only the gateways (and the queue) are replaced: the real pipeline still
     * runs, so events, usage records, metrics and retries behave as in production.
     *
     * Example:
     * ```php
     * Jev::fake(['department' => 'billing', 'is_urgent' => true])->withUsage(input: 120);
     *
     * $this->post('/tickets', [...]);
     *
     * Jev::assertEvaluated(fn (DecisionRequest $r) => $r->context->get('ticket_id') === 42);
     * ```
     *
     * @param  array<string, mixed>  $answers  Question id => answer (see {@see JevFake::__construct()}).
     * @return JevFake The fake, to configure further.
     */
    public static function fake(array $answers = []): JevFake
    {
        $fake = new JevFake($answers);
        $app = self::getFacadeApplication() ?? throw new RuntimeException('Jev::fake() needs a Laravel application.');

        $app->instance(JevFake::class, $fake);
        $app->instance(DecisionGateway::class, $fake);
        $app->instance(WebContextGateway::class, $fake);
        $app->instance(AccountGateway::class, $fake);
        $app->instance(DecisionQueue::class, $fake);
        $app->forgetInstance(JevClient::class);
        self::clearResolvedInstance(JevContract::class);

        return $fake;
    }

    /**
     * Forward assertion calls to the installed fake; anything else goes to the client.
     *
     * Example:
     * ```php
     * Jev::assertEvaluatedTimes(1);
     * ```
     *
     * @param  string  $method  Method name.
     * @param  array<int, mixed>  $args  Arguments.
     * @return mixed The method's return value.
     *
     * @throws RuntimeException When an assertion is called before `Jev::fake()`.
     */
    public static function __callStatic($method, $args): mixed
    {
        if (str_starts_with($method, 'assert')) {
            $app = self::getFacadeApplication();

            if ($app === null || ! $app->bound(JevFake::class)) {
                throw new RuntimeException("Call Jev::fake() before Jev::{$method}().");
            }

            $fake = $app->make(JevFake::class);

            return $fake instanceof JevFake ? $fake->{$method}(...$args) : null;
        }

        return parent::__callStatic($method, $args);
    }
}
