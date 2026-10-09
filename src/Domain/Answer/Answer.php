<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Answer;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Question\Question;
use Sysborg\LaravelJevai\Domain\Question\QuestionType;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * Jev's answer to one question.
 *
 * @api
 */
abstract readonly class Answer
{
    /**
     * Create the answer.
     *
     * Example:
     * ```php
     * new NoulAnswer('is_urgent', 0.95);
     * ```
     *
     * @param  string  $questionId  Id of the question this answers.
     *
     * @throws InvalidValue When the question id is blank or longer than 64 characters.
     */
    public function __construct(
        public string $questionId,
    ) {
        Guard::identifier($questionId, Question::MAX_ID_LENGTH, 'answer question id');
    }

    /**
     * The type of question this answers.
     *
     * Example:
     * ```php
     * $answer->type(); // QuestionType::Choice
     * ```
     *
     * @return QuestionType The question type.
     */
    abstract public function type(): QuestionType;
}
