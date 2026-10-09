<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use InvalidArgumentException;

/**
 * A value object received data that breaks one of its invariants.
 *
 * Messages name the offending field but never echo the value, because
 * values may carry user data (state, trace, context).
 */
final class InvalidValue extends InvalidArgumentException implements JevThrowable
{
    /**
     * Build the exception for a field and the rule it broke.
     *
     * Example:
     * ```php
     * throw InvalidValue::because('question id', 'must not be blank');
     * // "Invalid question id: must not be blank."
     * ```
     *
     * @param  string  $field  Human readable name of the invalid field.
     * @param  string  $reason  Rule the value broke, phrased to follow "must ...".
     * @return self The exception, ready to be thrown.
     */
    public static function because(string $field, string $reason): self
    {
        return new self("Invalid {$field}: {$reason}.");
    }
}
