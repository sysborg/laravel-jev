<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Ports\Driven;

use Throwable;

/**
 * The tracing span of the work currently running inside {@see Tracer::span()}.
 *
 * @api
 */
interface Span
{
    /**
     * Set one attribute, e.g. once the response is known.
     *
     * Example:
     * ```php
     * $span->setAttribute('gen_ai.usage.input_tokens', $result->usage->inputTokens);
     * ```
     *
     * @param  string  $key  Attribute name, preferably an OpenTelemetry semantic convention.
     * @param  string|int|float|bool|null  $value  Attribute value; null removes or skips it.
     * @return void Nothing.
     */
    public function setAttribute(string $key, string|int|float|bool|null $value): void;

    /**
     * Set several attributes at once.
     *
     * Example:
     * ```php
     * $span->setAttributes(['jev.billing.mode' => 'tokens', 'jev.credits_charged' => 0.0]);
     * ```
     *
     * @param  array<string, string|int|float|bool|null>  $attributes  Name => value.
     * @return void Nothing.
     */
    public function setAttributes(array $attributes): void;

    /**
     * Record a failure and mark the span as errored.
     *
     * Example:
     * ```php
     * $span->recordException($exception);
     * ```
     *
     * @param  Throwable  $exception  The failure; its message must not contain secrets.
     * @return void Nothing.
     */
    public function recordException(Throwable $exception): void;
}
