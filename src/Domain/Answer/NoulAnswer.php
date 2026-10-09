<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Answer;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Question\QuestionType;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * Answer to a yes/no question.
 *
 * @api
 */
final readonly class NoulAnswer extends Answer
{
    /** Probability of "yes", from 0 to 1. */
    public float $noul;

    /**
     * Create the answer.
     *
     * Example:
     * ```php
     * new NoulAnswer('is_urgent', 0.95);
     * ```
     *
     * @param  string  $questionId  Id of the question this answers.
     * @param  float  $noul  Probability of "yes", from 0 to 1 (tiny float drift is clamped).
     *
     * @throws InvalidValue When the question id is invalid or `$noul` is not a probability.
     */
    public function __construct(string $questionId, float $noul)
    {
        parent::__construct($questionId);

        $this->noul = Guard::probability($noul, "answer [{$questionId}] noul");
    }

    /**
     * Always {@see QuestionType::Noul}.
     *
     * Example:
     * ```php
     * $answer->type(); // QuestionType::Noul
     * ```
     *
     * @return QuestionType The noul question type.
     */
    public function type(): QuestionType
    {
        return QuestionType::Noul;
    }

    /**
     * Whether the probability of "yes" reaches the threshold.
     *
     * Example:
     * ```php
     * $answer->isYes();    // noul >= 0.5
     * $answer->isYes(0.9); // stricter decision
     * ```
     *
     * @param  float  $threshold  Minimum probability counted as "yes".
     * @return bool True when `noul >= $threshold`.
     */
    public function isYes(float $threshold = 0.5): bool
    {
        return $this->noul >= $threshold;
    }
}
