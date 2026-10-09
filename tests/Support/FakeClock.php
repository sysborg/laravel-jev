<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Tests\Support;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Ports\Driven\Clock;

/**
 * Deterministic {@see Clock} for tests: every monotonic read advances by a fixed step.
 */
final class FakeClock implements Clock
{
    private float $monotonic = 0.0;

    /** @var list<int> */
    public array $sleeps = [];

    /**
     * Create the clock.
     *
     * Example:
     * ```php
     * $clock = new FakeClock(stepMs: 412); // each request appears to take 412 ms
     * ```
     *
     * @param  float  $stepMs  Milliseconds added on every {@see monotonicMs()} call after the first.
     * @param  DateTimeImmutable  $now  The fixed wall-clock time.
     */
    public function __construct(
        private readonly float $stepMs = 0.0,
        private readonly DateTimeImmutable $now = new DateTimeImmutable('2026-10-09 12:00:00'),
    ) {}

    /**
     * The fixed wall-clock time.
     *
     * Example:
     * ```php
     * $clock->now(); // 2026-10-09 12:00:00
     * ```
     *
     * @return DateTimeImmutable The time given to the constructor.
     */
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Return the current monotonic value, then advance it by the step.
     *
     * Example:
     * ```php
     * $clock->monotonicMs(); // 0.0, then 412.0, then 824.0...
     * ```
     *
     * @return float Milliseconds.
     */
    public function monotonicMs(): float
    {
        $current = $this->monotonic;
        $this->monotonic += $this->stepMs;

        return $current;
    }

    /**
     * Record the requested pause without waiting.
     *
     * Example:
     * ```php
     * $clock->sleep(250);
     * $clock->sleeps; // [250]
     * ```
     *
     * @param  int  $milliseconds  Requested pause.
     * @return void Nothing.
     */
    public function sleep(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
    }
}
