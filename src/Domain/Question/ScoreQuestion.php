<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Question;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * Place the state on an ordered scale of 2–10 levels.
 *
 * @api
 */
final readonly class ScoreQuestion extends Question
{
    public const int MIN_LEVELS = 2;

    public const int MAX_LEVELS = 10;

    /**
     * Level labels ordered from the lowest (level 0) to the highest.
     *
     * @var list<string>
     */
    public array $levels;

    /**
     * Create the question.
     *
     * Example:
     * ```php
     * new ScoreQuestion('frustration', 'How frustrated is the customer?', [
     *     'Calm', 'Frustrated', 'Very angry',
     * ]);
     * ```
     *
     * @param  string  $id  Key of the question, at most 64 characters.
     * @param  string  $instructions  What Jev should rate.
     * @param  list<string>  $levels  Labels ordered from the lowest level to the highest.
     *
     * @throws InvalidValue When the id or instructions are invalid, the levels are not a list,
     *                      there are fewer than 2 or more than 10 levels, or a label is blank.
     */
    public function __construct(string $id, string $instructions, array $levels)
    {
        parent::__construct($id, $instructions);

        if (! array_is_list($levels)) {
            throw InvalidValue::because("question [{$id}] levels", 'must be an ordered list');
        }

        Guard::between(count($levels), self::MIN_LEVELS, self::MAX_LEVELS, "question [{$id}] level count");

        foreach ($levels as $index => $label) {
            Guard::notBlank($label, "question [{$id}] level [{$index}]");
        }

        $this->levels = $levels;
    }

    /**
     * Always {@see QuestionType::Score}.
     *
     * Example:
     * ```php
     * $question->type(); // QuestionType::Score
     * ```
     *
     * @return QuestionType The score question type.
     */
    public function type(): QuestionType
    {
        return QuestionType::Score;
    }
}
