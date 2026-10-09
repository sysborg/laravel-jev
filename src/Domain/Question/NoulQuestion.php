<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Question;

/**
 * Yes/no question; Jev answers with the probability of "yes".
 *
 * Example:
 * ```php
 * new NoulQuestion('is_urgent', 'Does this convey urgency?');
 * ```
 */
final readonly class NoulQuestion extends Question
{
    /**
     * Always {@see QuestionType::Noul}.
     *
     * Example:
     * ```php
     * (new NoulQuestion('is_urgent', 'Urgent?'))->type(); // QuestionType::Noul
     * ```
     *
     * @return QuestionType The noul question type.
     */
    public function type(): QuestionType
    {
        return QuestionType::Noul;
    }
}
