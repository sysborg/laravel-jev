<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Ports\Driven;

/**
 * Records counters and histograms (OpenTelemetry, Pulse, or nothing).
 */
interface MetricsRecorder
{
    /**
     * Add to a counter.
     *
     * Example:
     * ```php
     * $metrics->increment('jev.tokens.input', $result->usage->inputTokens, ['model' => 'jev-latest']);
     * ```
     *
     * @param  string  $name  Metric name, e.g. `jev.requests`.
     * @param  int|float  $value  Amount to add, zero or positive.
     * @param  array<string, string|int|float|bool|null>  $attributes  Low-cardinality dimensions.
     * @return void Nothing.
     */
    public function increment(string $name, int|float $value = 1, array $attributes = []): void;

    /**
     * Record one observation in a histogram.
     *
     * Example:
     * ```php
     * $metrics->histogram('jev.latency', $result->meta->latencyMs, ['model' => 'jev-latest']);
     * ```
     *
     * @param  string  $name  Metric name, e.g. `jev.latency`.
     * @param  int|float  $value  The observed value.
     * @param  array<string, string|int|float|bool|null>  $attributes  Low-cardinality dimensions.
     * @return void Nothing.
     */
    public function histogram(string $name, int|float $value, array $attributes = []): void;
}
