<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Decision;

use Sysborg\LaravelJevai\Domain\Answer\Answer;
use Sysborg\LaravelJevai\Domain\Answer\AnswerSet;
use Sysborg\LaravelJevai\Domain\Answer\ChoiceAnswer;
use Sysborg\LaravelJevai\Domain\Answer\NoulAnswer;
use Sysborg\LaravelJevai\Domain\Answer\ScoreAnswer;
use Sysborg\LaravelJevai\Domain\Exceptions\AnswerNotFound;
use Sysborg\LaravelJevai\Domain\Exceptions\AnswerTypeMismatch;
use Sysborg\LaravelJevai\Domain\Run\RunMetadata;
use Sysborg\LaravelJevai\Domain\Usage\Billing;
use Sysborg\LaravelJevai\Domain\Usage\Usage;

/**
 * Everything Jev returned for a decision call.
 */
final readonly class DecisionResult
{
    /**
     * Create the result.
     *
     * Example:
     * ```php
     * new DecisionResult($answers, new Usage(120, 8), $billing, $meta);
     * ```
     *
     * @param  AnswerSet  $answers  Answers keyed by question id.
     * @param  Usage  $usage  Tokens consumed.
     * @param  Billing  $billing  What was charged and what is left.
     * @param  RunMetadata  $meta  Correlation id, model, latency, attempts and Jev ids.
     * @param  array<string, mixed>|null  $raw  Decoded response body, kept only when configured.
     */
    public function __construct(
        public AnswerSet $answers,
        public Usage $usage,
        public Billing $billing,
        public RunMetadata $meta,
        public ?array $raw = null,
    ) {}

    /**
     * Get an answer of any type.
     *
     * Example:
     * ```php
     * $result->answer('department');
     * ```
     *
     * @param  string  $questionId  The question id.
     * @return Answer The answer.
     *
     * @throws AnswerNotFound When Jev did not answer that question.
     */
    public function answer(string $questionId): Answer
    {
        return $this->answers->get($questionId);
    }

    /**
     * Get a yes/no answer.
     *
     * Example:
     * ```php
     * $result->noul('is_urgent')->isYes();
     * ```
     *
     * @param  string  $questionId  The question id.
     * @return NoulAnswer The answer.
     *
     * @throws AnswerNotFound When Jev did not answer that question.
     * @throws AnswerTypeMismatch When the answer is not a noul answer.
     */
    public function noul(string $questionId): NoulAnswer
    {
        return $this->answers->noul($questionId);
    }

    /**
     * Get a choice answer.
     *
     * Example:
     * ```php
     * $result->choice('department')->choice; // 'billing'
     * ```
     *
     * @param  string  $questionId  The question id.
     * @return ChoiceAnswer The answer.
     *
     * @throws AnswerNotFound When Jev did not answer that question.
     * @throws AnswerTypeMismatch When the answer is not a choice answer.
     */
    public function choice(string $questionId): ChoiceAnswer
    {
        return $this->answers->choice($questionId);
    }

    /**
     * Get a score answer.
     *
     * Example:
     * ```php
     * $result->score('frustration')->label(); // 'Frustrated'
     * ```
     *
     * @param  string  $questionId  The question id.
     * @return ScoreAnswer The answer.
     *
     * @throws AnswerNotFound When Jev did not answer that question.
     * @throws AnswerTypeMismatch When the answer is not a score answer.
     */
    public function score(string $questionId): ScoreAnswer
    {
        return $this->answers->score($questionId);
    }

    /**
     * Return a copy without the raw response body, e.g. before storing or dispatching it.
     *
     * Example:
     * ```php
     * event(new DecisionSucceeded($result->withoutRaw()));
     * ```
     *
     * @return self A new result with `raw` set to null.
     */
    public function withoutRaw(): self
    {
        return new self($this->answers, $this->usage, $this->billing, $this->meta);
    }
}
