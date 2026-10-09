<?php

declare(strict_types=1);

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as SpanExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Sysborg\LaravelJevai\Adapters\Log\LogTracer;
use Sysborg\LaravelJevai\Adapters\Null\CompositeMetricsRecorder;
use Sysborg\LaravelJevai\Adapters\OpenTelemetry\OtelMetricsRecorder;
use Sysborg\LaravelJevai\Adapters\OpenTelemetry\OtelTracer;
use Sysborg\LaravelJevai\Ports\Driven\Span;
use Sysborg\LaravelJevai\Tests\Support\ArrayLogger;
use Sysborg\LaravelJevai\Tests\Support\FakeClock;
use Sysborg\LaravelJevai\Tests\Support\RecordingMetrics;

describe('LogTracer', function () {
    it('logs each finished span with its duration and attributes', function () {
        $logger = new ArrayLogger;
        $tracer = new LogTracer($logger, new FakeClock(stepMs: 42));

        $result = $tracer->span('jev.decision', ['gen_ai.system' => 'jev', 'gen_ai.request.model' => null], function (Span $span): string {
            $span->setAttributes(['jev.attempts' => 2]);

            return 'ok';
        });

        expect($result)->toBe('ok')
            ->and($logger->records[0]['level'])->toBe('debug')
            ->and($logger->records[0]['message'])->toBe('Jev span jev.decision')
            ->and($logger->records[0]['context'])->toMatchArray([
                'span' => 'jev.decision',
                'duration_ms' => 42,
                'status' => 'ok',
                'exception' => null,
                'attributes' => ['gen_ai.system' => 'jev', 'jev.attempts' => 2],
            ]);
    });

    it('marks failed spans and rethrows', function () {
        $logger = new ArrayLogger;

        expect(fn () => (new LogTracer($logger, new FakeClock))->span('jev.decision', [], fn () => throw new RuntimeException('secret detail')))
            ->toThrow(RuntimeException::class);

        expect($logger->records[0]['context']['status'])->toBe('error')
            ->and($logger->records[0]['context']['exception'])->toBe(RuntimeException::class)
            ->and(json_encode($logger->records))->not->toContain('secret detail');
    });
});

describe('OpenTelemetry', function () {
    it('exports one client span with attributes and status', function () {
        $exporter = new SpanExporter;
        $tracer = new OtelTracer((new TracerProvider(new SimpleSpanProcessor($exporter)))->getTracer('test'));

        $tracer->span('jev.decision', ['gen_ai.system' => 'jev', 'gen_ai.request.model' => null], function (Span $span): void {
            $span->setAttribute('gen_ai.usage.input_tokens', 120);
            $span->setAttributes(['jev.run_id' => null, 'jev.attempts' => 1]);
        });

        $span = $exporter->getSpans()[0];

        expect($span->getName())->toBe('jev.decision')
            ->and($span->getKind())->toBe(SpanKind::KIND_CLIENT)
            ->and($span->getAttributes()->toArray())->toBe([
                'gen_ai.system' => 'jev',
                'gen_ai.usage.input_tokens' => 120,
                'jev.attempts' => 1,
            ])
            ->and($span->getStatus()->getCode())->toBe(StatusCode::STATUS_OK);
    });

    it('records exceptions and marks the span as errored', function () {
        $exporter = new SpanExporter;
        $tracer = new OtelTracer((new TracerProvider(new SimpleSpanProcessor($exporter)))->getTracer('test'));

        expect(fn () => $tracer->span('jev.decision', [], fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);

        $span = $exporter->getSpans()[0];

        expect($span->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
            ->and($span->getEvents()[0]->getName())->toBe('exception');
    });

    it('exports counters and histograms with units', function () {
        $exporter = new MetricExporter;
        $reader = new ExportingReader($exporter);
        $metrics = new OtelMetricsRecorder(MeterProvider::builder()->addReader($reader)->build()->getMeter('test'));

        $metrics->increment('jev.tokens.input', 120, ['model' => 'clef', 'error' => null]);
        $metrics->increment('jev.tokens.input', 30, ['model' => 'clef']);
        $metrics->increment('jev.tokens.input', -5, ['model' => 'clef']);
        $metrics->histogram('jev.latency', 400, ['model' => 'clef']);
        $reader->collect();

        $byName = [];
        foreach ($exporter->collect() as $metric) {
            $byName[$metric->name] = $metric;
        }

        $tokens = $byName['jev.tokens.input'];
        $latency = $byName['jev.latency'];

        expect($tokens->unit)->toBe('{token}')
            ->and($tokens->data)->toBeInstanceOf(Sum::class)
            ->and($tokens->data->dataPoints[0]->value)->toBe(150)
            ->and($tokens->data->dataPoints[0]->attributes->toArray())->toBe(['model' => 'clef'])
            ->and($latency->unit)->toBe('ms')
            ->and($latency->data)->toBeInstanceOf(Histogram::class)
            ->and($latency->data->dataPoints[0]->sum)->toBe(400);
    });
});

it('fans metrics out to every recorder', function () {
    $a = new RecordingMetrics;
    $b = new RecordingMetrics;
    $composite = new CompositeMetricsRecorder($a, $b);

    $composite->increment('jev.requests', 1, ['status' => 'success']);
    $composite->histogram('jev.latency', 400);

    expect($a->points)->toHaveCount(2)->and($b->points)->toEqual($a->points);
});
