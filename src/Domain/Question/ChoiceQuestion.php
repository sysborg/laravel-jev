<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Question;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * Pick one of 2–255 named options.
 *
 * @api
 */
final readonly class ChoiceQuestion extends Question
{
    public const int MIN_OPTIONS = 2;

    public const int MAX_OPTIONS = 255;

    /**
     * Option name => description of when to pick it.
     *
     * @var array<array-key, string>
     */
    public array $criteria;

    /**
     * Create the question.
     *
     * Example:
     * ```php
     * new ChoiceQuestion('department', 'Which team should handle this?', [
     *     'billing' => 'Payments and refunds',
     *     'technical' => 'Bugs and outages',
     *     'sales' => 'Pricing',
     * ]);
     * ```
     *
     * @param  string  $id  Key of the question, at most 64 characters.
     * @param  string  $instructions  What Jev should decide.
     * @param  array<array-key, string>  $criteria  Option name => description of when to pick it.
     *
     * @throws InvalidValue When the id or instructions are invalid, there are fewer than 2 or more
     *                      than 255 options, or an option name or description is blank.
     */
    public function __construct(string $id, string $instructions, array $criteria)
    {
        parent::__construct($id, $instructions);

        Guard::between(count($criteria), self::MIN_OPTIONS, self::MAX_OPTIONS, "question [{$id}] option count");

        foreach ($criteria as $option => $description) {
            Guard::notBlank((string) $option, "question [{$id}] option name");
            Guard::notBlank($description, "question [{$id}] option [{$option}] description");
        }

        $this->criteria = $criteria;
    }

    /**
     * Always {@see QuestionType::Choice}.
     *
     * Example:
     * ```php
     * $question->type(); // QuestionType::Choice
     * ```
     *
     * @return QuestionType The choice question type.
     */
    public function type(): QuestionType
    {
        return QuestionType::Choice;
    }

    /**
     * The option names, in the order they were given.
     *
     * Numeric names are returned as strings, as Jev sees them.
     *
     * Example:
     * ```php
     * $question->options(); // ['billing', 'technical', 'sales']
     * ```
     *
     * @return list<string> The option names.
     */
    public function options(): array
    {
        return array_map(strval(...), array_keys($this->criteria));
    }
}
