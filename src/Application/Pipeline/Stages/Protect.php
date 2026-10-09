<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline\Stages;

use Closure;
use Psr\Log\LoggerInterface;
use Sysborg\LaravelJevai\Application\Pipeline\Call;
use Sysborg\LaravelJevai\Application\Pipeline\Middleware;
use Sysborg\LaravelJevai\Application\Support\SafeEventPublisher;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Events\CircuitClosed;
use Sysborg\LaravelJevai\Domain\Events\CircuitOpened;
use Sysborg\LaravelJevai\Domain\Exceptions\CircuitOpen;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Exceptions\LocallyRateLimited;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;
use Sysborg\LaravelJevai\Domain\Exceptions\UpstreamFailure;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\CounterStore;
use Throwable;

/**
 * Guards every HTTP attempt (runs inside the Retry stage), per connection:
 *
 * - **Circuit breaker**: after `failureThreshold` consecutive upstream failures (502/503/504,
 *   timeouts), attempts fail fast with {@see CircuitOpen} for `cooldownSeconds`; then calls
 *   are let through again and the next success closes it ({@see CircuitOpened} / {@see CircuitClosed}).
 * - **Rate limiter**: at most `perMinute` attempts per calendar minute; beyond that it either
 *   waits (`block`, up to `maxWaitSeconds`) or throws {@see LocallyRateLimited}.
 *
 * Both use a shared {@see CounterStore}. If the store fails, the guard logs a warning and
 * lets the attempt through (fail open): protection must never cause an outage.
 */
final readonly class Protect implements Middleware
{
    /**
     * Create the stage. Both guards are off unless their limit is given.
     *
     * Example:
     * ```php
     * new Protect($store, $clock, $events, $logger, perMinute: 1000, failureThreshold: 5, cooldownSeconds: 30);
     * ```
     *
     * @param  CounterStore  $store  Shared counters.
     * @param  Clock  $clock  Defines windows and sleeps when blocking.
     * @param  SafeEventPublisher  $events  Publishes circuit events.
     * @param  LoggerInterface  $logger  Receives store failures.
     * @param  int|null  $perMinute  Attempts allowed per minute and connection; null disables the limiter.
     * @param  bool  $block  Wait for the next window instead of throwing.
     * @param  int  $maxWaitSeconds  Longest wait when blocking; longer waits throw.
     * @param  int|null  $failureThreshold  Consecutive upstream failures that open the circuit; null disables it.
     * @param  int  $cooldownSeconds  How long the circuit stays open.
     */
    public function __construct(
        private CounterStore $store,
        private Clock $clock,
        private SafeEventPublisher $events,
        private LoggerInterface $logger,
        private ?int $perMinute = null,
        private bool $block = false,
        private int $maxWaitSeconds = 10,
        private ?int $failureThreshold = null,
        private int $cooldownSeconds = 30,
    ) {}

    /**
     * Guard one attempt.
     *
     * Example:
     * ```php
     * $stage->handle($call, $next); // throws CircuitOpen while the circuit is open
     * ```
     *
     * @param  Call  $call  The call.
     * @param  Closure(Call): (DecisionResult|WebContextResult)  $next  Sends the attempt.
     * @return DecisionResult|WebContextResult The result, unchanged.
     *
     * @throws CircuitOpen When the circuit is open.
     * @throws LocallyRateLimited When the per-minute limit is reached and not blocking.
     * @throws JevException When the attempt fails.
     */
    public function handle(Call $call, Closure $next): DecisionResult|WebContextResult
    {
        $scope = 'jev:'.($call->connection ?? 'default');

        $this->checkCircuit($call, $scope);
        $this->throttle($call, $scope);

        try {
            $result = $next($call);
        } catch (UpstreamFailure|TransportFailure $e) {
            $this->recordFailure($call, $scope);

            throw $e;
        }

        $this->recordSuccess($call, $scope);

        return $result;
    }

    /**
     * Fail fast while the circuit is open.
     *
     * Example:
     * ```php
     * $this->checkCircuit($call, 'jev:default');
     * ```
     *
     * @param  Call  $call  The call.
     * @param  string  $scope  Key prefix of the connection.
     * @return void Nothing.
     *
     * @throws CircuitOpen When the circuit is open.
     */
    private function checkCircuit(Call $call, string $scope): void
    {
        if ($this->failureThreshold === null) {
            return;
        }

        $openUntil = $this->safely($call, fn (): int => $this->store->get("{$scope}:circuit:open_until"), 0);
        $remaining = $openUntil - $this->now();

        if ($remaining > 0) {
            throw new CircuitOpen($remaining, $call->connection);
        }
    }

    /**
     * Count the attempt in the current minute, waiting or throwing above the limit.
     *
     * Example:
     * ```php
     * $this->throttle($call, 'jev:default');
     * ```
     *
     * @param  Call  $call  The call.
     * @param  string  $scope  Key prefix of the connection.
     * @return void Nothing.
     *
     * @throws LocallyRateLimited When the limit is reached and not blocking (or the wait is too long).
     */
    private function throttle(Call $call, string $scope): void
    {
        if ($this->perMinute === null) {
            return;
        }

        $wait = $this->hit($call, $scope);

        if ($wait === 0) {
            return;
        }

        if (! $this->block || $wait > $this->maxWaitSeconds) {
            throw new LocallyRateLimited($wait, $this->perMinute);
        }

        $this->clock->sleep($wait * 1000);

        $wait = $this->hit($call, $scope);

        if ($wait > 0) {
            throw new LocallyRateLimited($wait, $this->perMinute);
        }
    }

    /**
     * Count one attempt in the current minute.
     *
     * Example:
     * ```php
     * $this->hit($call, 'jev:default'); // 0 when allowed, else seconds until the next minute
     * ```
     *
     * @param  Call  $call  The call.
     * @param  string  $scope  Key prefix of the connection.
     * @return int 0 when the attempt is within the limit, otherwise seconds until the window ends.
     */
    private function hit(Call $call, string $scope): int
    {
        $now = $this->now();
        $count = $this->safely($call, fn (): int => $this->store->increment($scope.':rate:'.intdiv($now, 60), 1, 120), 0);

        return $count <= (int) $this->perMinute ? 0 : 60 - ($now % 60);
    }

    /**
     * Count an upstream failure, opening the circuit at the threshold.
     *
     * Example:
     * ```php
     * $this->recordFailure($call, 'jev:default');
     * ```
     *
     * @param  Call  $call  The call.
     * @param  string  $scope  Key prefix of the connection.
     * @return void Nothing.
     */
    private function recordFailure(Call $call, string $scope): void
    {
        if ($this->failureThreshold === null) {
            return;
        }

        $failures = $this->safely($call, fn (): int => $this->store->increment("{$scope}:circuit:failures", 1, 3600), 0);

        if ($failures < $this->failureThreshold) {
            return;
        }

        $this->safely($call, function () use ($scope): int {
            $this->store->put("{$scope}:circuit:open_until", $this->now() + $this->cooldownSeconds, $this->cooldownSeconds);

            return 0;
        }, 0);

        $alreadyOpen = $this->safely($call, fn (): int => $this->store->get("{$scope}:circuit:opened"), 1);

        if ($alreadyOpen === 0) {
            $this->safely($call, function () use ($scope): int {
                $this->store->put("{$scope}:circuit:opened", 1, 86_400);

                return 0;
            }, 0);

            $this->events->publish(new CircuitOpened(
                $call->correlationId(),
                $call->context(),
                $this->clock->now(),
                $failures,
                $this->cooldownSeconds,
                $call->connection,
            ));
        }
    }

    /**
     * Reset the failure count and close an opened circuit.
     *
     * Example:
     * ```php
     * $this->recordSuccess($call, 'jev:default');
     * ```
     *
     * @param  Call  $call  The call.
     * @param  string  $scope  Key prefix of the connection.
     * @return void Nothing.
     */
    private function recordSuccess(Call $call, string $scope): void
    {
        if ($this->failureThreshold === null) {
            return;
        }

        $this->safely($call, function () use ($scope): int {
            $this->store->forget("{$scope}:circuit:failures");

            return 0;
        }, 0);

        if ($this->safely($call, fn (): int => $this->store->get("{$scope}:circuit:opened"), 0) === 1) {
            $this->safely($call, function () use ($scope): int {
                $this->store->forget("{$scope}:circuit:opened");

                return 0;
            }, 0);

            $this->events->publish(new CircuitClosed($call->correlationId(), $call->context(), $this->clock->now(), $call->connection));
        }
    }

    /**
     * Run a store operation, failing open with a warning.
     *
     * Example:
     * ```php
     * $this->safely($call, fn () => $this->store->get('key'), 0);
     * ```
     *
     * @param  Call  $call  The call, for log context.
     * @param  Closure(): int  $operation  The store operation.
     * @param  int  $fallback  Value used when the store fails.
     * @return int The operation's value, or the fallback.
     */
    private function safely(Call $call, Closure $operation, int $fallback): int
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            $this->logger->warning('The Jev rate limiter / circuit breaker store failed; the attempt was let through.', [
                'correlation_id' => (string) $call->correlationId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * The current Unix time.
     *
     * Example:
     * ```php
     * $this->now(); // 1791577080
     * ```
     *
     * @return int Seconds since the epoch.
     */
    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
