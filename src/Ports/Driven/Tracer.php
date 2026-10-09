<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Ports\Driven;

/**
 * Wraps work in tracing spans (OpenTelemetry, logs, or nothing).
 */
interface Tracer
{
    /**
     * Run a callback inside a span, ending the span when the callback returns or throws.
     *
     * Exceptions are recorded on the span and rethrown unchanged.
     *
     * Example:
     * ```php
     * $result = $tracer->span('jev.decide', ['gen_ai.request.model' => 'jev-latest'],
     *     function (Span $span) use ($gateway, $request, $id) {
     *         $result = $gateway->decide($request, $id);
     *         $span->setAttribute('gen_ai.usage.input_tokens', $result->usage->inputTokens);
     *
     *         return $result;
     *     },
     * );
     * ```
     *
     * @template TReturn
     *
     * @param  string  $name  Span name.
     * @param  array<string, string|int|float|bool|null>  $attributes  Attributes known before the work starts.
     * @param  callable(Span): TReturn  $callback  The work to trace.
     * @return TReturn Whatever the callback returns.
     */
    public function span(string $name, array $attributes, callable $callback): mixed;
}
