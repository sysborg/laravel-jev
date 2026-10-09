<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Question;

use Countable;
use Generator;
use IteratorAggregate;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * The 1–64 uniquely identified questions of a single decision request.
 *
 * @implements IteratorAggregate<string, Question>
 *
 * @api
 */
final readonly class QuestionSet implements Countable, IteratorAggregate
{
    public const int MAX_QUESTIONS = 64;

    /** @var list<Question> */
    private array $questions;

    /**
     * Create the set.
     *
     * Example:
     * ```php
     * new QuestionSet(
     *     new NoulQuestion('is_urgent', 'Urgent?'),
     *     new ScoreQuestion('frustration', 'How frustrated?', ['Calm', 'Angry']),
     * );
     * ```
     *
     * @param  Question  ...$questions  The questions, in the order they should be sent.
     *
     * @throws InvalidValue When there are no questions, more than 64, or two share an id.
     */
    public function __construct(Question ...$questions)
    {
        $questions = array_values($questions);

        Guard::between(count($questions), 1, self::MAX_QUESTIONS, 'question count');

        $seen = [];

        foreach ($questions as $question) {
            if (isset($seen[$question->id])) {
                throw InvalidValue::because("question id [{$question->id}]", 'must be unique');
            }

            $seen[$question->id] = true;
        }

        $this->questions = $questions;
    }

    /**
     * Named constructor, same as `new QuestionSet(...)`.
     *
     * Example:
     * ```php
     * QuestionSet::of(new NoulQuestion('is_urgent', 'Urgent?'));
     * ```
     *
     * @param  Question  ...$questions  The questions, in the order they should be sent.
     * @return self The new set.
     *
     * @throws InvalidValue When there are no questions, more than 64, or two share an id.
     */
    public static function of(Question ...$questions): self
    {
        return new self(...$questions);
    }

    /**
     * Return a new set with one more question appended.
     *
     * Example:
     * ```php
     * $set = $set->with(new NoulQuestion('is_spam', 'Is this spam?'));
     * ```
     *
     * @param  Question  $question  The question to add.
     * @return self A new set; the original is unchanged.
     *
     * @throws InvalidValue When the set would exceed 64 questions or the id is already used.
     */
    public function with(Question $question): self
    {
        return new self(...[...$this->questions, $question]);
    }

    /**
     * Whether a question with this id is in the set.
     *
     * Example:
     * ```php
     * $set->has('is_urgent'); // true
     * ```
     *
     * @param  string  $id  The question id to look for.
     * @return bool True when the question exists.
     */
    public function has(string $id): bool
    {
        return $this->get($id) !== null;
    }

    /**
     * Find a question by id.
     *
     * Example:
     * ```php
     * $set->get('is_urgent'); // NoulQuestion
     * $set->get('missing');   // null
     * ```
     *
     * @param  string  $id  The question id to look for.
     * @return Question|null The question, or null when it is not in the set.
     */
    public function get(string $id): ?Question
    {
        foreach ($this->questions as $question) {
            if ($question->id === $id) {
                return $question;
            }
        }

        return null;
    }

    /**
     * The question ids, in order.
     *
     * Example:
     * ```php
     * $set->ids(); // ['is_urgent', 'frustration']
     * ```
     *
     * @return list<string> The ids.
     */
    public function ids(): array
    {
        return array_map(static fn (Question $question): string => $question->id, $this->questions);
    }

    /**
     * The questions, in order.
     *
     * Example:
     * ```php
     * foreach ($set->all() as $question) {
     *     echo $question->id;
     * }
     * ```
     *
     * @return list<Question> The questions.
     */
    public function all(): array
    {
        return $this->questions;
    }

    /**
     * Number of questions in the set.
     *
     * Example:
     * ```php
     * count($set); // 2
     * ```
     *
     * @return int The number of questions, from 1 to 64.
     */
    public function count(): int
    {
        return count($this->questions);
    }

    /**
     * Iterate over the questions keyed by id (ids stay strings, even numeric ones).
     *
     * Example:
     * ```php
     * foreach ($set as $id => $question) {
     *     echo "{$id}: {$question->instructions}";
     * }
     * ```
     *
     * @return Generator<string, Question> Id => question.
     */
    public function getIterator(): Generator
    {
        foreach ($this->questions as $question) {
            yield $question->id => $question;
        }
    }
}
