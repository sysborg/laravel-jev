<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Decision;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * A saved Jev judge, optionally pinned to a revision.
 *
 * Without a revision the latest rules are used. With one, Jev answers 409
 * when the rules have changed since.
 */
final readonly class JudgeRef
{
    public const int MAX_ID_LENGTH = 256;

    /**
     * Reference a saved judge.
     *
     * Example:
     * ```php
     * new JudgeRef('judge_123');    // always the latest rules
     * new JudgeRef('judge_123', 3); // fail with 409 if the rules changed after revision 3
     * ```
     *
     * @param  string  $id  The judge id from the Jev app.
     * @param  int|null  $revision  Revision to pin, or null for the latest.
     *
     * @throws InvalidValue When the id is blank or too long, or the revision is lower than 1.
     */
    public function __construct(
        public string $id,
        public ?int $revision = null,
    ) {
        Guard::identifier($id, self::MAX_ID_LENGTH, 'judge id');

        if ($revision !== null && $revision < 1) {
            throw InvalidValue::because("judge [{$id}] revision", 'must be a positive integer');
        }
    }

    /**
     * Whether the reference is pinned to a revision.
     *
     * Example:
     * ```php
     * (new JudgeRef('judge_123', 3))->isPinned(); // true
     * ```
     *
     * @return bool True when a revision is set.
     */
    public function isPinned(): bool
    {
        return $this->revision !== null;
    }
}
