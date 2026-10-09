<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\System;

use DateTimeImmutable;
use DateTimeZone;
use Sysborg\LaravelJevai\Ports\Driven\Clock;

/**
 * {@see Clock} backed by the system clock and `hrtime()`.
 */
final class SystemClock implements Clock
{
    /**
     * The current time in UTC.
     *
     * Example:
     * ```php
     * (new SystemClock)->now(); // 2026-10-09 14:03:12.123456 UTC
     * ```
     *
     * @return DateTimeImmutable The current time.
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * A monotonic timestamp from `hrtime()`.
     *
     * Example:
     * ```php
     * $start = $clock->monotonicMs();
     * ```
     *
     * @return float Milliseconds from an arbitrary origin.
     */
    public function monotonicMs(): float
    {
        return hrtime(true) / 1_000_000;
    }

    /**
     * Block the current process.
     *
     * Example:
     * ```php
     * $clock->sleep(250);
     * ```
     *
     * @param  int  $milliseconds  How long to wait; values below 1 return immediately.
     * @return void Nothing.
     */
    public function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
