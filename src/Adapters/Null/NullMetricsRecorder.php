<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Null;

use Sysborg\LaravelJevai\Ports\Driven\MetricsRecorder;

/**
 * Metrics recorder used when metrics are disabled.
 */
final class NullMetricsRecorder implements MetricsRecorder
{
    /**
     * Ignore the counter increment.
     *
     * Example:
     * ```php
     * (new NullMetricsRecorder)->increment('jev.requests'); // no-op
     * ```
     *
     * @param  string  $name  Ignored.
     * @param  int|float  $value  Ignored.
     * @param  array<string, string|int|float|bool|null>  $attributes  Ignored.
     * @return void Nothing.
     */
    public function increment(string $name, int|float $value = 1, array $attributes = []): void {}

    /**
     * Ignore the observation.
     *
     * Example:
     * ```php
     * (new NullMetricsRecorder)->histogram('jev.latency', 412); // no-op
     * ```
     *
     * @param  string  $name  Ignored.
     * @param  int|float  $value  Ignored.
     * @param  array<string, string|int|float|bool|null>  $attributes  Ignored.
     * @return void Nothing.
     */
    public function histogram(string $name, int|float $value, array $attributes = []): void {}
}
