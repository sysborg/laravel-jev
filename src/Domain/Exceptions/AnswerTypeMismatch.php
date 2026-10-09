<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use Sysborg\LaravelJevai\Domain\Question\QuestionType;
use UnexpectedValueException;

/**
 * An answer was read as a type it is not, e.g. a noul answer read as a choice.
 */
final class AnswerTypeMismatch extends UnexpectedValueException implements JevThrowable
{
    /**
     * Build the exception for the requested and actual answer types.
     *
     * Example:
     * ```php
     * throw AnswerTypeMismatch::for('is_urgent', QuestionType::Choice, QuestionType::Noul);
     * // "Answer for question [is_urgent] is of type [noul], [choice] was requested."
     * ```
     *
     * @param  string  $questionId  Id of the question whose answer was read.
     * @param  QuestionType  $expected  Type the caller asked for.
     * @param  QuestionType  $actual  Type Jev actually answered with.
     * @return self The exception, ready to be thrown.
     */
    public static function for(string $questionId, QuestionType $expected, QuestionType $actual): self
    {
        return new self(
            "Answer for question [{$questionId}] is of type [{$actual->value}], [{$expected->value}] was requested."
        );
    }
}
