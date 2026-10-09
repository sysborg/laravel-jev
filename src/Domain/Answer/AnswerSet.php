<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Answer;

use Countable;
use Generator;
use IteratorAggregate;
use Sysborg\LaravelJevai\Domain\Exceptions\AnswerNotFound;
use Sysborg\LaravelJevai\Domain\Exceptions\AnswerTypeMismatch;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Question\QuestionType;

/**
 * Answers of a decision, keyed by question id.
 *
 * @implements IteratorAggregate<string, Answer>
 */
final readonly class AnswerSet implements Countable, IteratorAggregate
{
    /** @var list<Answer> */
    private array $answers;

    /**
     * Create the set.
     *
     * Example:
     * ```php
     * new AnswerSet(
     *     new NoulAnswer('is_urgent', 0.95),
     *     new ChoiceAnswer('department', 'billing'),
     * );
     * ```
     *
     * @param  Answer  ...$answers  The answers, in the order Jev returned them.
     *
     * @throws InvalidValue When two answers share a question id.
     */
    public function __construct(Answer ...$answers)
    {
        $answers = array_values($answers);
        $seen = [];

        foreach ($answers as $answer) {
            if (isset($seen[$answer->questionId])) {
                throw InvalidValue::because("answer for question [{$answer->questionId}]", 'must be unique');
            }

            $seen[$answer->questionId] = true;
        }

        $this->answers = $answers;
    }

    /**
     * Named constructor, same as `new AnswerSet(...)`.
     *
     * Example:
     * ```php
     * AnswerSet::of(new NoulAnswer('is_urgent', 0.95));
     * ```
     *
     * @param  Answer  ...$answers  The answers, in the order Jev returned them.
     * @return self The new set.
     *
     * @throws InvalidValue When two answers share a question id.
     */
    public static function of(Answer ...$answers): self
    {
        return new self(...$answers);
    }

    /**
     * Whether an answer exists for the question.
     *
     * Example:
     * ```php
     * $answers->has('is_urgent'); // true
     * ```
     *
     * @param  string  $questionId  The question id.
     * @return bool True when Jev answered that question.
     */
    public function has(string $questionId): bool
    {
        return $this->find($questionId) !== null;
    }

    /**
     * Find an answer without throwing.
     *
     * Example:
     * ```php
     * $answers->find('missing'); // null
     * ```
     *
     * @param  string  $questionId  The question id.
     * @return Answer|null The answer, or null when Jev did not answer that question.
     */
    public function find(string $questionId): ?Answer
    {
        foreach ($this->answers as $answer) {
            if ($answer->questionId === $questionId) {
                return $answer;
            }
        }

        return null;
    }

    /**
     * Get an answer of any type.
     *
     * Example:
     * ```php
     * $answer = $answers->get('department');
     * ```
     *
     * @param  string  $questionId  The question id.
     * @return Answer The answer.
     *
     * @throws AnswerNotFound When Jev did not answer that question.
     */
    public function get(string $questionId): Answer
    {
        return $this->find($questionId) ?? throw AnswerNotFound::for($questionId);
    }

    /**
     * Get a yes/no answer.
     *
     * Example:
     * ```php
     * $answers->noul('is_urgent')->isYes(); // true
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
        $answer = $this->get($questionId);

        return $answer instanceof NoulAnswer
            ? $answer
            : throw AnswerTypeMismatch::for($questionId, QuestionType::Noul, $answer->type());
    }

    /**
     * Get a choice answer.
     *
     * Example:
     * ```php
     * $answers->choice('department')->choice; // 'billing'
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
        $answer = $this->get($questionId);

        return $answer instanceof ChoiceAnswer
            ? $answer
            : throw AnswerTypeMismatch::for($questionId, QuestionType::Choice, $answer->type());
    }

    /**
     * Get a score answer.
     *
     * Example:
     * ```php
     * $answers->score('frustration')->label(); // 'Frustrated'
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
        $answer = $this->get($questionId);

        return $answer instanceof ScoreAnswer
            ? $answer
            : throw AnswerTypeMismatch::for($questionId, QuestionType::Score, $answer->type());
    }

    /**
     * The answered question ids, in order.
     *
     * Example:
     * ```php
     * $answers->ids(); // ['is_urgent', 'department']
     * ```
     *
     * @return list<string> The ids.
     */
    public function ids(): array
    {
        return array_map(static fn (Answer $answer): string => $answer->questionId, $this->answers);
    }

    /**
     * The answers, in order.
     *
     * Example:
     * ```php
     * foreach ($answers->all() as $answer) {
     *     echo $answer->questionId;
     * }
     * ```
     *
     * @return list<Answer> The answers.
     */
    public function all(): array
    {
        return $this->answers;
    }

    /**
     * Number of answers.
     *
     * Example:
     * ```php
     * count($answers); // 2
     * ```
     *
     * @return int The number of answers.
     */
    public function count(): int
    {
        return count($this->answers);
    }

    /**
     * Iterate over the answers keyed by question id (ids stay strings, even numeric ones).
     *
     * Example:
     * ```php
     * foreach ($answers as $questionId => $answer) {
     *     echo "{$questionId}: {$answer->type()->value}";
     * }
     * ```
     *
     * @return Generator<string, Answer> Question id => answer.
     */
    public function getIterator(): Generator
    {
        foreach ($this->answers as $answer) {
            yield $answer->questionId => $answer;
        }
    }
}
