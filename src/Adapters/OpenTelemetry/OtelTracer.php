<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\OpenTelemetry;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Sysborg\LaravelJevai\Ports\Driven\Span;
use Sysborg\LaravelJevai\Ports\Driven\Tracer;
use Throwable;

/**
 * {@see Tracer} on OpenTelemetry: one CLIENT span per Jev call, made current while
 * it runs so the HTTP request (and anything else) nests under it.
 */
final readonly class OtelTracer implements Tracer
{
    public const string INSTRUMENTATION = 'sysborg/laravel-jevai';

    /**
     * Create the tracer.
     *
     * Example:
     * ```php
     * new OtelTracer(Globals::tracerProvider()->getTracer(OtelTracer::INSTRUMENTATION));
     * ```
     *
     * @param  TracerInterface  $tracer  The OpenTelemetry tracer.
     */
    public function __construct(
        private TracerInterface $tracer,
    ) {}

    /**
     * Run the work inside an active span.
     *
     * Example:
     * ```php
     * $tracer->span('jev.decision', ['gen_ai.system' => 'jev'], fn (Span $span) => $gateway->decide(...));
     * ```
     *
     * @template TReturn
     *
     * @param  string  $name  Span name.
     * @param  array<string, string|int|float|bool|null>  $attributes  Initial attributes; nulls are skipped.
     * @param  callable(Span): TReturn  $callback  The work.
     * @return TReturn Whatever the callback returns.
     *
     * @throws Throwable Whatever the callback throws, after it was recorded on the span.
     */
    public function span(string $name, array $attributes, callable $callback): mixed
    {
        $span = $this->tracer->spanBuilder($name === '' ? 'jev' : $name)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttributes(Attributes::of($attributes))
            ->startSpan();
        $scope = $span->activate();

        try {
            $result = $callback(new OtelSpan($span));
            $span->setStatus(StatusCode::STATUS_OK);

            return $result;
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e::class);

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
