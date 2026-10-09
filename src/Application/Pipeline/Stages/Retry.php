<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline\Stages;

use Closure;
use Psr\Log\LoggerInterface;
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
     * new Retry(new RetryPolicy(maxAttempts: 3), $clock, $events, $logger);
     * ```
     *
     * @param  RetryPolicy  $policy  When and how long to wait.
     * @param  Clock  $clock  Measures elapsed time and sleeps between attempts.
     * @param  SafeEventPublisher  $events  Publishes {@see RetryScheduled}.
     * @param  LoggerInterface|null  $logger  Logs each retry as a warning, when given.
     */
    public function __construct(
        private RetryPolicy $policy,
        private Clock $clock,
        private SafeEventPublisher $events,
        private ?LoggerInterface $logger = null,
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

                $this->logRetry($call, $e, $delay);
                $this->clock->sleep($delay);

                continue;
            }

            return $result->withMeta(
                $result->meta->withTiming($call->elapsedMs($this->clock->monotonicMs()), $call->attempts),
            );
        }
    }

    /**
     * Log a scheduled retry as a warning, never breaking the call.
     *
     * Example:
     * ```php
     * $this->logRetry($call, $e, 400); // "Jev attempt failed; retrying."
     * ```
     *
     * @param  Call  $call  The call.
     * @param  JevException  $exception  Why the attempt failed.
     * @param  int  $delayMs  Wait before the next attempt.
     * @return void Nothing.
     */
    private function logRetry(Call $call, JevException $exception, int $delayMs): void
    {
        try {
            $this->logger?->warning('Jev attempt failed; retrying.', [
                'correlation_id' => (string) $call->correlationId,
                'operation' => $call->operation->value,
                'model' => $call->model(),
                'connection' => $call->connection,
                'failed_attempt' => $call->attempts,
                'delay_ms' => $delayMs,
                'exception' => $exception::class,
                'http_status' => $exception->httpStatus,
            ]);
        } catch (\Throwable) {
            // A broken log channel must not break the Jev call.
        }
    }
}
