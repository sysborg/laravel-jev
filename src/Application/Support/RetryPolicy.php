<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Support;

use Random\Randomizer;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Exceptions\RateLimited;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;

/**
 * When and how long to wait before retrying a failed attempt.
 *
 * - 429: wait `Retry-After` (give up if it exceeds the cap), or back off when absent;
 * - 502/503/504: exponential backoff with jitter;
 * - timeouts / dropped connections: not retried unless enabled (the call may have been billed);
 * - everything else (400, 401, 402, 404, 409, 422, ...): never retried;
 * - always bounded by a maximum number of attempts and a total time budget.
 */
final readonly class RetryPolicy
{
    /**
     * Create the policy.
     *
     * Example:
     * ```php
     * new RetryPolicy(maxAttempts: 3, maxElapsedMs: 60_000, baseDelayMs: 250, maxDelayMs: 5_000);
     * ```
     *
     * @param  int  $maxAttempts  Attempts in total, the first one included; 1 disables retries.
     * @param  int  $maxElapsedMs  Never schedule a retry that would end after this budget.
     * @param  int  $baseDelayMs  Backoff of the first retry.
     * @param  int  $maxDelayMs  Upper bound of a single backoff.
     * @param  int  $maxRetryAfterSeconds  Longest `Retry-After` honored; longer waits fail fast.
     * @param  bool  $retryOnTimeout  Whether timeouts and dropped connections are retried (risks double billing).
     * @param  Randomizer  $random  Source of jitter; inject a seeded one in tests.
     *
     * @throws InvalidValue When a bound is negative or `$maxAttempts` is lower than 1.
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $maxElapsedMs = 60_000,
        public int $baseDelayMs = 250,
        public int $maxDelayMs = 5_000,
        public int $maxRetryAfterSeconds = 30,
        public bool $retryOnTimeout = false,
        private Randomizer $random = new Randomizer,
    ) {
        if ($maxAttempts < 1) {
            throw InvalidValue::because('retry max attempts', 'must be at least 1');
        }

        foreach (['max elapsed' => $maxElapsedMs, 'base delay' => $baseDelayMs, 'max delay' => $maxDelayMs, 'max retry-after' => $maxRetryAfterSeconds] as $name => $value) {
            if ($value < 0) {
                throw InvalidValue::because("retry {$name}", 'must not be negative');
            }
        }
    }

    /**
     * A policy that never retries.
     *
     * Example:
     * ```php
     * RetryPolicy::none()->delayMs($e, 1, 0); // null
     * ```
     *
     * @return self The policy with a single attempt.
     */
    public static function none(): self
    {
        return new self(maxAttempts: 1);
    }

    /**
     * How long to wait before the next attempt, or null to give up.
     *
     * Example:
     * ```php
     * $policy->delayMs(new UpstreamFailure(503), failedAttempt: 1, elapsedMs: 400); // e.g. 187
     * $policy->delayMs(new Unauthorized, failedAttempt: 1, elapsedMs: 120);         // null
     * ```
     *
     * @param  JevException  $exception  Why the attempt failed.
     * @param  int  $failedAttempt  Number of the attempt that failed, starting at 1.
     * @param  int  $elapsedMs  Time spent on the call so far.
     * @return int|null Milliseconds to wait, or null when the call must fail now.
     */
    public function delayMs(JevException $exception, int $failedAttempt, int $elapsedMs): ?int
    {
        if ($failedAttempt >= $this->maxAttempts) {
            return null;
        }

        $delay = match (true) {
            $exception instanceof RateLimited => $this->rateLimitDelayMs($exception, $failedAttempt),
            $exception instanceof TransportFailure => $this->retryOnTimeout ? $this->backoffMs($failedAttempt) : null,
            $exception->isRetryable() => $this->backoffMs($failedAttempt),
            default => null,
        };

        if ($delay === null || $elapsedMs + $delay > $this->maxElapsedMs) {
            return null;
        }

        return $delay;
    }

    /**
     * Exponential backoff with "equal jitter": between half and all of `base * 2^(n-1)`, capped.
     *
     * Example:
     * ```php
     * $policy->backoffMs(1); // 125..250
     * $policy->backoffMs(3); // 500..1000
     * ```
     *
     * @param  int  $failedAttempt  Number of the attempt that failed, starting at 1.
     * @return int Milliseconds to wait.
     */
    public function backoffMs(int $failedAttempt): int
    {
        $exponent = min(max($failedAttempt - 1, 0), 30);
        $ceiling = min($this->maxDelayMs, $this->baseDelayMs * (2 ** $exponent));

        return $ceiling <= 1 ? $ceiling : $this->random->getInt(intdiv($ceiling, 2), $ceiling);
    }

    /**
     * Delay after a 429: the server's `Retry-After` when present, backoff otherwise.
     *
     * Example:
     * ```php
     * $this->rateLimitDelayMs(new RateLimited(2), 1);  // 2000
     * $this->rateLimitDelayMs(new RateLimited(90), 1); // null with a 30 s cap
     * ```
     *
     * @param  RateLimited  $exception  The 429 failure.
     * @param  int  $failedAttempt  Number of the attempt that failed, starting at 1.
     * @return int|null Milliseconds to wait, or null when the server asks for longer than the cap.
     */
    private function rateLimitDelayMs(RateLimited $exception, int $failedAttempt): ?int
    {
        if ($exception->retryAfterSeconds === null) {
            return $this->backoffMs($failedAttempt);
        }

        return $exception->retryAfterSeconds > $this->maxRetryAfterSeconds
            ? null
            : $exception->retryAfterSeconds * 1000;
    }
}
