<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\OpenTelemetry;

use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use Sysborg\LaravelJevai\Ports\Driven\MetricsRecorder;

/**
 * {@see MetricsRecorder} on OpenTelemetry counters and histograms.
 *
 * Instruments are created on first use and reused.
 */
final class OtelMetricsRecorder implements MetricsRecorder
{
    /** @var array<string, CounterInterface> */
    private array $counters = [];

    /** @var array<string, HistogramInterface> */
    private array $histograms = [];

    /**
     * Create the recorder.
     *
     * Example:
     * ```php
     * new OtelMetricsRecorder(Globals::meterProvider()->getMeter(OtelTracer::INSTRUMENTATION));
     * ```
     *
     * @param  MeterInterface  $meter  The OpenTelemetry meter.
     */
    public function __construct(
        private readonly MeterInterface $meter,
    ) {}

    /**
     * Add to a counter; negative amounts are ignored (counters are monotonic).
     *
     * Example:
     * ```php
     * $metrics->increment('jev.tokens.input', 120, ['model' => 'jev-latest']);
     * ```
     *
     * @param  string  $name  Metric name.
     * @param  int|float  $value  Amount to add.
     * @param  array<string, string|int|float|bool|null>  $attributes  Dimensions; nulls are skipped.
     * @return void Nothing.
     */
    public function increment(string $name, int|float $value = 1, array $attributes = []): void
    {
        if ($value < 0) {
            return;
        }

        $this->counters[$name] ??= $this->meter->createCounter($name, self::unit($name));
        $this->counters[$name]->add($value, Attributes::of($attributes));
    }

    /**
     * Record an observation.
     *
     * Example:
     * ```php
     * $metrics->histogram('jev.latency', 412, ['model' => 'jev-latest']);
     * ```
     *
     * @param  string  $name  Metric name.
     * @param  int|float  $value  The observation.
     * @param  array<string, string|int|float|bool|null>  $attributes  Dimensions; nulls are skipped.
     * @return void Nothing.
     */
    public function histogram(string $name, int|float $value, array $attributes = []): void
    {
        $this->histograms[$name] ??= $this->meter->createHistogram($name, self::unit($name));
        $this->histograms[$name]->record($value, Attributes::of($attributes));
    }

    /**
     * Unit of a package metric (UCUM).
     *
     * Example:
     * ```php
     * self::unit('jev.latency'); // 'ms'
     * ```
     *
     * @param  string  $name  Metric name.
     * @return string|null The unit, or null when dimensionless.
     */
    private static function unit(string $name): ?string
    {
        return match (true) {
            $name === 'jev.latency' => 'ms',
            str_starts_with($name, 'jev.tokens.') => '{token}',
            $name === 'jev.credits.charged' => '{credit}',
            $name === 'jev.requests' => '{request}',
            default => null,
        };
    }
}
