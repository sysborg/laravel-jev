<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Run;

use Stringable;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;

/**
 * Ties one call to its events, logs, spans, usage rows and Jev's own trace.
 */
final readonly class CorrelationId implements Stringable
{
    private const string PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/';

    /**
     * Create the id.
     *
     * Example:
     * ```php
     * new CorrelationId('0192f1c4-7a0e-7c3b-9a8e-1f2d3c4b5a69');
     * ```
     *
     * @param  string  $value  1–128 characters of letters, digits, ".", "_", ":" or "-",
     *                         starting with a letter or digit (safe in headers and logs).
     *
     * @throws InvalidValue When the value does not match that format.
     */
    public function __construct(
        public string $value,
    ) {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidValue::because(
                'correlation id',
                'must be 1–128 characters of letters, digits, ".", "_", ":" or "-"',
            );
        }
    }

    /**
     * Whether both ids have the same value.
     *
     * Example:
     * ```php
     * if ($event->correlationId->equals($pending->correlationId)) {
     *     // this event belongs to our call
     * }
     * ```
     *
     * @param  self  $other  The id to compare with.
     * @return bool True when the values are identical.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The raw value.
     *
     * Example:
     * ```php
     * Log::info('Jev call', ['correlation_id' => (string) $correlationId]);
     * ```
     *
     * @return string The id.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
