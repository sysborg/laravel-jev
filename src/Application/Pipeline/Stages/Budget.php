<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline\Stages;

use Closure;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Sysborg\LaravelJevai\Application\Pipeline\Call;
use Sysborg\LaravelJevai\Application\Pipeline\Middleware;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\BudgetExceeded;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\CounterStore;
use Throwable;

/**
 * Daily budget of charged input tokens, per connection and UTC day.
 *
 * Checked once per call before anything is sent; the tokens Jev charged are
 * added after each successful call. Calls already running when the budget is
 * reached still complete, so the budget may be slightly exceeded. If the store
 * fails, the call is let through with a warning (fail open).
 */
final readonly class Budget implements Middleware
{
    /**
     * Create the stage.
     *
     * Example:
     * ```php
     * new Budget($store, $clock, $logger, dailyInputTokens: 5_000_000);
     * ```
     *
     * @param  CounterStore  $store  Shared counters.
     * @param  Clock  $clock  Defines the current UTC day.
     * @param  LoggerInterface  $logger  Receives store failures.
     * @param  int|null  $dailyInputTokens  Charged input tokens allowed per day; null disables the guard.
     */
    public function __construct(
        private CounterStore $store,
        private Clock $clock,
        private LoggerInterface $logger,
        private ?int $dailyInputTokens = null,
    ) {}

    /**
     * Check, run and count the call.
     *
     * Example:
     * ```php
     * $stage->handle($call, $next); // throws BudgetExceeded once today's tokens reach the limit
     * ```
     *
     * @param  Call  $call  The call.
     * @param  Closure(Call): (DecisionResult|WebContextResult)  $next  The rest of the pipeline.
     * @return DecisionResult|WebContextResult The result, unchanged.
     *
     * @throws BudgetExceeded When the daily budget is used up.
     * @throws JevException When the call fails.
     */
    public function handle(Call $call, Closure $next): DecisionResult|WebContextResult
    {
        if ($this->dailyInputTokens === null) {
            return $next($call);
        }

        $key = 'jev:'.($call->connection ?? 'default').':budget:'
            .$this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');

        $used = $this->safely($call, fn (): int => $this->store->get($key));

        if ($used >= $this->dailyInputTokens) {
            throw new BudgetExceeded($used, $this->dailyInputTokens);
        }

        $result = $next($call);
        $charged = $result->billing->inputTokensCharged;

        if ($charged > 0) {
            $this->safely($call, fn (): int => $this->store->increment($key, $charged, 2 * 86_400));
        }

        return $result;
    }

    /**
     * Run a store operation, failing open with a warning.
     *
     * Example:
     * ```php
     * $this->safely($call, fn () => $this->store->get($key));
     * ```
     *
     * @param  Call  $call  The call, for log context.
     * @param  Closure(): int  $operation  The store operation.
     * @return int The operation's value, or 0 when the store fails.
     */
    private function safely(Call $call, Closure $operation): int
    {
        try {
            return $operation();
        } catch (Throwable $e) {
            $this->logger->warning('The Jev budget store failed; the call was let through.', [
                'correlation_id' => (string) $call->correlationId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
