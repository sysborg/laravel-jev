<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Question;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * A named, typed question Jev answers about the request state.
 */
abstract readonly class Question
{
    public const int MAX_ID_LENGTH = 64;

    /**
     * Create the question.
     *
     * Example:
     * ```php
     * new NoulQuestion('is_urgent', 'Does this convey urgency?');
     * ```
     *
     * @param  string  $id  Key of the question in the request and of its answer, at most 64 characters.
     * @param  string  $instructions  What Jev should decide, in plain language.
     *
     * @throws InvalidValue When the id is blank or longer than 64 characters, or the instructions are blank.
     */
    public function __construct(
        public string $id,
        public string $instructions,
    ) {
        Guard::identifier($id, self::MAX_ID_LENGTH, 'question id');
        Guard::notBlank($instructions, "question [{$id}] instructions");
    }

    /**
     * The kind of question, which defines the shape of its answer.
     *
     * Example:
     * ```php
     * $question->type(); // QuestionType::Noul
     * ```
     *
     * @return QuestionType The question type sent to Jev as `type`.
     */
    abstract public function type(): QuestionType;
}
