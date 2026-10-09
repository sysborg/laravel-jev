<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Answer;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Question\QuestionType;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * Answer to a score question.
 */
final readonly class ScoreAnswer extends Answer
{
    /** Expected level, e.g. 1.04 on a 0–2 scale. */
    public float $score;

    public ?float $confidence;

    /**
     * Level => label.
     *
     * @var array<int, string>
     */
    public array $legend;

    /**
     * Level => probability.
     *
     * @var array<int, float>
     */
    public array $probabilities;

    /**
     * Create the answer.
     *
     * Example:
     * ```php
     * new ScoreAnswer(
     *     'frustration',
     *     1.04,
     *     0.94,
     *     [0 => 'Calm', 1 => 'Frustrated', 2 => 'Very angry'],
     *     [0 => 0.0, 1 => 0.96, 2 => 0.04],
     * );
     * ```
     *
     * @param  string  $questionId  Id of the question this answers.
     * @param  float  $score  Expected level, a non-negative number.
     * @param  float|null  $confidence  Confidence from 0 to 1, when returned.
     * @param  array<int, string>  $legend  Level => label, when returned.
     * @param  array<int, int|float>  $probabilities  Level => probability, when returned.
     *
     * @throws InvalidValue When the question id is invalid, the score is negative, or a
     *                      probability or the confidence is not a number between 0 and 1.
     */
    public function __construct(
        string $questionId,
        float $score,
        ?float $confidence = null,
        array $legend = [],
        array $probabilities = [],
    ) {
        parent::__construct($questionId);

        $this->score = Guard::nonNegativeFloat($score, "answer [{$questionId}] score");
        $this->confidence = $confidence === null
            ? null
            : Guard::probability($confidence, "answer [{$questionId}] confidence");
        $this->legend = $legend;
        $this->probabilities = Guard::probabilities($probabilities, "answer [{$questionId}] probabilities");
    }

    /**
     * Always {@see QuestionType::Score}.
     *
     * Example:
     * ```php
     * $answer->type(); // QuestionType::Score
     * ```
     *
     * @return QuestionType The score question type.
     */
    public function type(): QuestionType
    {
        return QuestionType::Score;
    }

    /**
     * The most probable level, or the rounded score when no distribution was returned.
     *
     * Example:
     * ```php
     * $answer->level(); // 1
     * ```
     *
     * @return int The level, starting at 0.
     */
    public function level(): int
    {
        if ($this->probabilities === []) {
            return (int) round($this->score);
        }

        $level = array_search(max($this->probabilities), $this->probabilities, true);

        return (int) $level;
    }

    /**
     * The label of {@see level()}, when a legend was returned.
     *
     * Example:
     * ```php
     * $answer->label(); // 'Frustrated'
     * ```
     *
     * @return string|null The label, or null without a legend entry for that level.
     */
    public function label(): ?string
    {
        return $this->legend[$this->level()] ?? null;
    }
}
