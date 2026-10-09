<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Null;

use Sysborg\LaravelJevai\Ports\Driven\MetricsRecorder;

/**
 * Sends every metric to several recorders (e.g. OpenTelemetry and Pulse).
 */
final readonly class CompositeMetricsRecorder implements MetricsRecorder
{
    /** @var list<MetricsRecorder> */
    private array $recorders;

    /**
     * Create the composite.
     *
     * Example:
     * ```php
     * new CompositeMetricsRecorder($otel, $pulse);
     * ```
     *
     * @param  MetricsRecorder  ...$recorders  The recorders.
     */
    public function __construct(MetricsRecorder ...$recorders)
    {
        $this->recorders = array_values($recorders);
    }

    /**
     * Add to a counter on every recorder.
     *
     * Example:
     * ```php
     * $metrics->increment('jev.requests', 1, ['status' => 'success']);
     * ```
     *
     * @param  string  $name  Metric name.
     * @param  int|float  $value  Amount.
     * @param  array<string, string|int|float|bool|null>  $attributes  Dimensions.
     * @return void Nothing.
     */
    public function increment(string $name, int|float $value = 1, array $attributes = []): void
    {
        foreach ($this->recorders as $recorder) {
            $recorder->increment($name, $value, $attributes);
        }
    }

    /**
     * Record an observation on every recorder.
     *
     * Example:
     * ```php
     * $metrics->histogram('jev.latency', 412);
     * ```
     *
     * @param  string  $name  Metric name.
     * @param  int|float  $value  Observation.
     * @param  array<string, string|int|float|bool|null>  $attributes  Dimensions.
     * @return void Nothing.
     */
    public function histogram(string $name, int|float $value, array $attributes = []): void
    {
        foreach ($this->recorders as $recorder) {
            $recorder->histogram($name, $value, $attributes);
        }
    }
}
