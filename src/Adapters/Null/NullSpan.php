<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Null;

use Sysborg\LaravelJevai\Ports\Driven\Span;
use Throwable;

/**
 * A span that records nothing.
 */
final class NullSpan implements Span
{
    /**
     * Ignore the attribute.
     *
     * Example:
     * ```php
     * (new NullSpan)->setAttribute('gen_ai.system', 'jev'); // no-op
     * ```
     *
     * @param  string  $key  Ignored.
     * @param  string|int|float|bool|null  $value  Ignored.
     * @return void Nothing.
     */
    public function setAttribute(string $key, string|int|float|bool|null $value): void {}

    /**
     * Ignore the attributes.
     *
     * Example:
     * ```php
     * (new NullSpan)->setAttributes(['jev.attempts' => 2]); // no-op
     * ```
     *
     * @param  array<string, string|int|float|bool|null>  $attributes  Ignored.
     * @return void Nothing.
     */
    public function setAttributes(array $attributes): void {}

    /**
     * Ignore the exception.
     *
     * Example:
     * ```php
     * (new NullSpan)->recordException($e); // no-op
     * ```
     *
     * @param  Throwable  $exception  Ignored.
     * @return void Nothing.
     */
    public function recordException(Throwable $exception): void {}
}
