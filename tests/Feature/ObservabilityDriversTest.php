<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Adapters\Log\LogTracer;
use Sysborg\LaravelJevai\Adapters\Null\CompositeMetricsRecorder;
use Sysborg\LaravelJevai\Adapters\Null\NullMetricsRecorder;
use Sysborg\LaravelJevai\Adapters\Null\NullTracer;
use Sysborg\LaravelJevai\Adapters\OpenTelemetry\OtelMetricsRecorder;
use Sysborg\LaravelJevai\Adapters\OpenTelemetry\OtelTracer;
use Sysborg\LaravelJevai\Adapters\Pulse\PulseMetricsRecorder;
use Sysborg\LaravelJevai\Application\Pipeline\Pipeline;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Log;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Ports\Driven\MetricsRecorder;
use Sysborg\LaravelJevai\Ports\Driven\Tracer;

it('resolves the tracing driver', function (string $driver, string $class) {
    config()->set('jev.observability.tracing', $driver);

    expect(app(Tracer::class))->toBeInstanceOf($class);
})->with([
    ['null', NullTracer::class],
    ['log', LogTracer::class],
    ['otel', OtelTracer::class],
    ['OTEL', OtelTracer::class],
]);

it('resolves one or several metrics drivers', function (string $drivers, string $class) {
    config()->set('jev.observability.metrics', $drivers);

    expect(app(MetricsRecorder::class))->toBeInstanceOf($class);
})->with([
    ['null', NullMetricsRecorder::class],
    ['', NullMetricsRecorder::class],
    ['otel', OtelMetricsRecorder::class],
    ['pulse', PulseMetricsRecorder::class],
    ['otel, pulse', CompositeMetricsRecorder::class],
]);

it('rejects unknown drivers', function (string $key, string $driver) {
    config()->set("jev.observability.{$key}", $driver);

    app($key === 'tracing' ? Tracer::class : MetricsRecorder::class);
})->throws(InvalidValue::class)->with([
    ['tracing', 'zipkin'],
    ['metrics', 'statsd'],
]);

it('adds the call log stage only when enabled', function (bool $enabled) {
    config()->set('jev.logging.calls', $enabled);

    $stages = array_map(fn ($stage) => $stage::class, app(Pipeline::class)->stages());

    expect(in_array(Log::class, $stages, true))->toBe($enabled);
})->with([true, false]);
