<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Log;

use Sysborg\LaravelJevai\Ports\Driven\Span;
use Throwable;

/**
 * Span collecting attributes until {@see LogTracer} writes it to the log.
 */
final class LogSpan implements Span
{
    /** @var array<string, string|int|float|bool> */
    public array $attributes = [];

    /** Class of the recorded exception, if any. */
    public ?string $exception = null;

    /**
     * Create the span.
     *
     * Example:
     * ```php
     * new LogSpan(['gen_ai.system' => 'jev']);
     * ```
     *
     * @param  array<string, string|int|float|bool|null>  $attributes  Initial attributes; nulls are skipped.
     */
    public function __construct(array $attributes = [])
    {
        $this->setAttributes($attributes);
    }

    /**
     * Set one attribute; null removes it.
     *
     * Example:
     * ```php
     * $span->setAttribute('jev.attempts', 2);
     * ```
     *
     * @param  string  $key  Attribute name.
     * @param  string|int|float|bool|null  $value  Attribute value.
     * @return void Nothing.
     */
    public function setAttribute(string $key, string|int|float|bool|null $value): void
    {
        if ($value === null) {
            unset($this->attributes[$key]);

            return;
        }

        $this->attributes[$key] = $value;
    }

    /**
     * Set several attributes.
     *
     * Example:
     * ```php
     * $span->setAttributes(['jev.billing.mode' => 'tokens']);
     * ```
     *
     * @param  array<string, string|int|float|bool|null>  $attributes  Name => value.
     * @return void Nothing.
     */
    public function setAttributes(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
    }

    /**
     * Remember the failure class (never its message, which may hold user data).
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
        $this->exception = $exception::class;
    }
}
