<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Null;

use Sysborg\LaravelJevai\Ports\Driven\Span;
use Sysborg\LaravelJevai\Ports\Driven\Tracer;

/**
 * Tracer used when tracing is disabled: runs the work without recording spans.
 */
final class NullTracer implements Tracer
{
    /**
     * Run the callback with a span that records nothing.
     *
     * Example:
     * ```php
     * (new NullTracer)->span('jev.decision', [], fn ($span) => 42); // 42
     * ```
     *
     * @template TReturn
     *
     * @param  string  $name  Ignored.
     * @param  array<string, string|int|float|bool|null>  $attributes  Ignored.
     * @param  callable(Span): TReturn  $callback  The work.
     * @return TReturn Whatever the callback returns.
     */
    public function span(string $name, array $attributes, callable $callback): mixed
    {
        return $callback(new NullSpan);
    }
}
