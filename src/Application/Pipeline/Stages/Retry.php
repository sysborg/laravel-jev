<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline\Stages;

use Closure;
use Sysborg\LaravelJevai\Application\Pipeline\Call;
use Sysborg\LaravelJevai\Application\Pipeline\Middleware;
use Sysborg\LaravelJevai\Application\Support\RetryPolicy;
use Sysborg\LaravelJevai\Application\Support\SafeEventPublisher;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Events\RetryScheduled;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Ports\Driven\Clock;

/**
 * Repeats failed attempts according to the {@see RetryPolicy} and stamps the final timing.
 */
final readonly class Retry implements Middleware
{
    /**
     * Create the stage.
     *
     * Example:
     * ```php
     * new Retry(new RetryPolicy(maxAttempts: 3), $clock, $events);
     * ```
     *
     * @param  RetryPolicy  $policy  When and how long to wait.
     * @param  Clock  $clock  Measures elapsed time and sleeps between attempts.
     * @param  SafeEventPublisher  $events  Publishes {@see RetryScheduled}.
     */
    public function __construct(
        private RetryPolicy $policy,
        private Clock $clock,
        private SafeEventPublisher $events,
    ) {}

    /**
     * Run attempts until one succeeds or the policy gives up.
     *
     * Example:
     * ```php
     * $result = $stage->handle($call, $next);
     * $result->meta->attempts; // 2 after one retried 503
     * ```
     *
     * @param  Call  $call  The call; `attempts` is incremented for every attempt.
     * @param  Closure(Call): (DecisionResult|WebContextResult)  $next  Sends one attempt.
     * @return DecisionResult|WebContextResult The result, with total latency and attempts.
     *
     * @throws JevException The last failure, when the policy gives up.
     */
    public function handle(Call $call, Closure $next): DecisionResult|WebContextResult
    {
        while (true) {
            $call->attempts++;

            try {
                $result = $next($call);
            } catch (JevException $e) {
                $delay = $this->policy->delayMs($e, $call->attempts, $call->elapsedMs($this->clock->monotonicMs()));

                if ($delay === null) {
                    throw $e;
                }

                $this->events->publish(RetryScheduled::fromException(
                    $call->operation,
                    $call->correlationId(),
                    $call->context(),
                    $this->clock->now(),
                    $e,
                    $call->attempts,
                    $delay,
                    $call->model(),
                    $call->connection,
                ));

                $this->clock->sleep($delay);

                continue;
            }

            return $result->withMeta(
                $result->meta->withTiming($call->elapsedMs($this->clock->monotonicMs()), $call->attempts),
            );
        }
    }
}
