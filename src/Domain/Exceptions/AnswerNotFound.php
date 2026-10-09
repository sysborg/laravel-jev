<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use OutOfBoundsException;

/**
 * An answer was requested for a question id that Jev did not answer.
 */
final class AnswerNotFound extends OutOfBoundsException implements JevThrowable
{
    /**
     * Build the exception for the missing question id.
     *
     * Example:
     * ```php
     * throw AnswerNotFound::for('is_urgent');
     * // "No answer was returned for question [is_urgent]."
     * ```
     *
     * @param  string  $questionId  Id of the question without an answer.
     * @return self The exception, ready to be thrown.
     */
    public static function for(string $questionId): self
    {
        return new self("No answer was returned for question [{$questionId}].");
    }
}
