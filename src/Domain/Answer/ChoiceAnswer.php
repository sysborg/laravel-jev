<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Answer;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Question\QuestionType;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * Answer to a choice question.
 */
final readonly class ChoiceAnswer extends Answer
{
    public ?float $confidence;

    /**
     * Option name => probability.
     *
     * @var array<array-key, float>
     */
    public array $probabilities;

    /**
     * Create the answer.
     *
     * Example:
     * ```php
     * new ChoiceAnswer('department', 'billing', 0.98, [
     *     'billing' => 0.99, 'technical' => 0.01, 'sales' => 0.0,
     * ]);
     * ```
     *
     * @param  string  $questionId  Id of the question this answers.
     * @param  string  $choice  The option Jev picked.
     * @param  float|null  $confidence  Confidence in the choice from 0 to 1, when returned.
     * @param  array<array-key, int|float>  $probabilities  Option name => probability, when returned.
     *
     * @throws InvalidValue When the question id or choice is blank, or a probability or the
     *                      confidence is not a number between 0 and 1.
     */
    public function __construct(
        string $questionId,
        public string $choice,
        ?float $confidence = null,
        array $probabilities = [],
    ) {
        parent::__construct($questionId);

        Guard::notBlank($choice, "answer [{$questionId}] choice");

        $this->confidence = $confidence === null
            ? null
            : Guard::probability($confidence, "answer [{$questionId}] confidence");
        $this->probabilities = Guard::probabilities($probabilities, "answer [{$questionId}] probabilities");
    }

    /**
     * Always {@see QuestionType::Choice}.
     *
     * Example:
     * ```php
     * $answer->type(); // QuestionType::Choice
     * ```
     *
     * @return QuestionType The choice question type.
     */
    public function type(): QuestionType
    {
        return QuestionType::Choice;
    }

    /**
     * Whether Jev picked the given option.
     *
     * Example:
     * ```php
     * if ($answer->is('billing')) {
     *     $ticket->assignTo('billing');
     * }
     * ```
     *
     * @param  string  $option  The option name to compare with.
     * @return bool True when it is the chosen option.
     */
    public function is(string $option): bool
    {
        return $this->choice === $option;
    }

    /**
     * Probability Jev gave to an option.
     *
     * Example:
     * ```php
     * $answer->probability('technical'); // 0.01
     * $answer->probability('unknown');   // null
     * ```
     *
     * @param  string  $option  The option name.
     * @return float|null The probability, or null when it was not returned.
     */
    public function probability(string $option): ?float
    {
        return $this->probabilities[$option] ?? null;
    }
}
