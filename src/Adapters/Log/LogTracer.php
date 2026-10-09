<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Log;

use Psr\Log\LoggerInterface;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\Span;
use Sysborg\LaravelJevai\Ports\Driven\Tracer;
use Throwable;

/**
 * {@see Tracer} writing each finished span to the log at debug level.
 *
 * Handy locally or where no tracing backend exists (`JEV_AI_TRACING=log`).
 */
final readonly class LogTracer implements Tracer
{
    /**
     * Create the tracer.
     *
     * Example:
     * ```php
     * new LogTracer(Log::channel('jev'), new SystemClock);
     * ```
     *
     * @param  LoggerInterface  $logger  Where spans are written.
     * @param  Clock  $clock  Measures span durations.
     */
    public function __construct(
        private LoggerInterface $logger,
        private Clock $clock,
    ) {}

    /**
     * Run the work and log the span when it ends.
     *
     * Example:
     * ```php
     * $tracer->span('jev.decision', ['gen_ai.system' => 'jev'], fn (Span $span) => $gateway->decide(...));
     * // log: "Jev span jev.decision" {duration_ms, status, attributes}
     * ```
     *
     * @template TReturn
     *
     * @param  string  $name  Span name.
     * @param  array<string, string|int|float|bool|null>  $attributes  Initial attributes.
     * @param  callable(Span): TReturn  $callback  The work.
     * @return TReturn Whatever the callback returns.
     *
     * @throws Throwable Whatever the callback throws, after the span was logged.
     */
    public function span(string $name, array $attributes, callable $callback): mixed
    {
        $span = new LogSpan($attributes);
        $started = $this->clock->monotonicMs();

        try {
            return $callback($span);
        } catch (Throwable $e) {
            $span->recordException($e);

            throw $e;
        } finally {
            $this->write($name, $span, $started);
        }
    }

    /**
     * Write the finished span, never breaking the call.
     *
     * Example:
     * ```php
     * $this->write('jev.decision', $span, $started);
     * ```
     *
     * @param  string  $name  Span name.
     * @param  LogSpan  $span  The finished span.
     * @param  float  $started  Monotonic start time in milliseconds.
     * @return void Nothing.
     */
    private function write(string $name, LogSpan $span, float $started): void
    {
        try {
            $this->logger->debug("Jev span {$name}", [
                'span' => $name,
                'duration_ms' => max(0, (int) round($this->clock->monotonicMs() - $started)),
                'status' => $span->exception === null ? 'ok' : 'error',
                'exception' => $span->exception,
                'attributes' => $span->attributes,
            ]);
        } catch (Throwable) {
            // A broken log channel must not break the Jev call.
        }
    }
}
