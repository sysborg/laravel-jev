<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Ports\Driven;

use DateTimeImmutable;

/**
 * Time source, replaceable in tests so retries and latency are deterministic.
 *
 * @api
 */
interface Clock
{
    /**
     * The current wall-clock time.
     *
     * Example:
     * ```php
     * $record = RunRecord::forDecision($request, $result, $clock->now());
     * ```
     *
     * @return DateTimeImmutable The current time.
     */
    public function now(): DateTimeImmutable;

    /**
     * A monotonic timestamp for measuring durations; unaffected by clock changes.
     *
     * Example:
     * ```php
     * $start = $clock->monotonicMs();
     * // ... call Jev ...
     * $latencyMs = (int) round($clock->monotonicMs() - $start);
     * ```
     *
     * @return float Milliseconds from an arbitrary origin.
     */
    public function monotonicMs(): float;

    /**
     * Pause before a retry.
     *
     * Example:
     * ```php
     * $clock->sleep(($e->retryAfterSeconds ?? 1) * 1000);
     * ```
     *
     * @param  int  $milliseconds  How long to wait.
     * @return void Nothing.
     */
    public function sleep(int $milliseconds): void;
}
