<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline\Stages;

use Closure;
use Psr\Log\LoggerInterface;
use Sysborg\LaravelJevai\Application\Pipeline\Call;
use Sysborg\LaravelJevai\Application\Pipeline\Middleware;
use Sysborg\LaravelJevai\Application\Support\SafeEventPublisher;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Events\BalanceLow;
use Sysborg\LaravelJevai\Domain\Events\CreditsExhausted;
use Sysborg\LaravelJevai\Domain\Events\JudgeRulesChanged;
use Sysborg\LaravelJevai\Domain\Exceptions\InsufficientCredits;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeRevisionMismatch;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\Debouncer;
use Throwable;

/**
 * Publishes the operational alerts that need someone's attention:
 *
 * - {@see BalanceLow} when the remaining paid input tokens drop below a threshold
 *   (at most once per debounce window and connection);
 * - {@see CreditsExhausted} when Jev refuses a call with 402;
 * - {@see JudgeRulesChanged} when a pinned judge call is refused with 409.
 *
 * Runs just outside the Emit stage, so alerts always follow the call's lifecycle events.
 */
final readonly class Alert implements Middleware
{
    /**
     * Create the stage.
     *
     * Example:
     * ```php
     * new Alert($events, $clock, $debouncer, $logger, tokensThreshold: 100_000, debounceSeconds: 3600);
     * ```
     *
     * @param  SafeEventPublisher  $events  Publishes without letting listeners break the call.
     * @param  Clock  $clock  Timestamps the events.
     * @param  Debouncer  $debouncer  Limits {@see BalanceLow} to once per window.
     * @param  LoggerInterface  $logger  Receives debouncer failures.
     * @param  int|null  $tokensThreshold  Alert below this many paid input tokens; null disables {@see BalanceLow}.
     * @param  int  $debounceSeconds  Minimum time between two {@see BalanceLow} for the same connection.
     */
    public function __construct(
        private SafeEventPublisher $events,
        private Clock $clock,
        private Debouncer $debouncer,
        private LoggerInterface $logger,
        private ?int $tokensThreshold = null,
        private int $debounceSeconds = 3600,
    ) {}

    /**
     * Watch the outcome of the call for alerts.
     *
     * Example:
     * ```php
     * $stage->handle($call, $next); // may publish BalanceLow after DecisionSucceeded
     * ```
     *
     * @param  Call  $call  The call.
     * @param  Closure(Call): (DecisionResult|WebContextResult)  $next  The rest of the pipeline.
     * @return DecisionResult|WebContextResult The result, unchanged.
     *
     * @throws JevException When the call fails (after any alert was published).
     */
    public function handle(Call $call, Closure $next): DecisionResult|WebContextResult
    {
        try {
            $result = $next($call);
        } catch (InsufficientCredits $e) {
            $this->events->publish(CreditsExhausted::fromException(
                $call->operation,
                $call->correlationId(),
                $call->context(),
                $this->clock->now(),
                $e,
                $call->model(),
                $call->connection,
            ));

            throw $e;
        } catch (JudgeRevisionMismatch $e) {
            $this->events->publish(JudgeRulesChanged::fromException(
                $call->correlationId(),
                $call->context(),
                $this->clock->now(),
                $e,
                $call->connection,
            ));

            throw $e;
        }

        $this->watchBalance($call, $result);

        return $result;
    }

    /**
     * Publish {@see BalanceLow} when the reported balance is under the threshold.
     *
     * Example:
     * ```php
     * $this->watchBalance($call, $result); // 8 000 tokens left, threshold 10 000 => BalanceLow
     * ```
     *
     * @param  Call  $call  The call.
     * @param  DecisionResult|WebContextResult  $result  Its result, with billing.
     * @return void Nothing.
     */
    private function watchBalance(Call $call, DecisionResult|WebContextResult $result): void
    {
        $remaining = $result->billing->tokensRemaining;

        if ($this->tokensThreshold === null || $remaining === null || $remaining >= $this->tokensThreshold) {
            return;
        }

        $connection = $result->meta->connection ?? $call->connection;

        try {
            $first = $this->debouncer->attempt('jev:balance-low:'.($connection ?? 'default'), $this->debounceSeconds);
        } catch (Throwable $e) {
            $this->logger->warning('Debouncing the Jev low-balance alert failed; the Jev call was not affected.', [
                'correlation_id' => (string) $call->correlationId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        if ($first) {
            $this->events->publish(new BalanceLow(
                $result->meta->correlationId,
                $call->context(),
                $this->clock->now(),
                $remaining,
                $this->tokensThreshold,
                $connection,
                $result->meta->model,
            ));
        }
    }
}
