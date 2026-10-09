<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\OpenTelemetry;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Sysborg\LaravelJevai\Ports\Driven\Span;
use Throwable;

/**
 * {@see Span} backed by an OpenTelemetry span.
 */
final readonly class OtelSpan implements Span
{
    /**
     * Wrap the span.
     *
     * Example:
     * ```php
     * new OtelSpan($tracer->spanBuilder('jev.decision')->startSpan());
     * ```
     *
     * @param  SpanInterface  $span  The OpenTelemetry span.
     */
    public function __construct(
        private SpanInterface $span,
    ) {}

    /**
     * Set one attribute; null values are skipped.
     *
     * Example:
     * ```php
     * $span->setAttribute('gen_ai.usage.input_tokens', 120);
     * ```
     *
     * @param  string  $key  Attribute name.
     * @param  string|int|float|bool|null  $value  Attribute value.
     * @return void Nothing.
     */
    public function setAttribute(string $key, string|int|float|bool|null $value): void
    {
        if ($key !== '' && $value !== null) {
            $this->span->setAttribute($key, $value);
        }
    }

    /**
     * Set several attributes; null values are skipped.
     *
     * Example:
     * ```php
     * $span->setAttributes(['jev.billing.mode' => 'tokens', 'jev.run_id' => null]);
     * ```
     *
     * @param  array<string, string|int|float|bool|null>  $attributes  Name => value.
     * @return void Nothing.
     */
    public function setAttributes(array $attributes): void
    {
        $this->span->setAttributes(Attributes::of($attributes));
    }

    /**
     * Record the exception and mark the span as errored.
     *
     * Example:
     * ```php
     * $span->recordException($e);
     * ```
     *
     * @param  Throwable  $exception  The failure.
     * @return void Nothing.
     */
    public function recordException(Throwable $exception): void
    {
        $this->span->recordException($exception);
        $this->span->setStatus(StatusCode::STATUS_ERROR, $exception::class);
    }
}
