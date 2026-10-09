<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use Throwable;

/**
 * 409: a revision was pinned but the judge rules have changed since.
 *
 * @api
 */
final class JudgeRevisionMismatch extends JevException
{
    /**
     * Create the exception with HTTP status 409.
     *
     * Example:
     * ```php
     * throw new JudgeRevisionMismatch('judge_123', 3);
     * // "Jev judge [judge_123] no longer matches revision [3]."
     * ```
     *
     * @param  string  $judgeId  Id of the judge whose rules changed.
     * @param  int|null  $requestedRevision  Revision the request was pinned to.
     * @param  string|null  $message  Custom message; a default naming the judge is used when null.
     * @param  string|null  $errorCode  `error.code` from Jev's error body, when present.
     * @param  Throwable|null  $previous  Underlying exception, e.g. the HTTP client error.
     */
    public function __construct(
        public readonly string $judgeId,
        public readonly ?int $requestedRevision = null,
        ?string $message = null,
        ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? "Jev judge [{$judgeId}] no longer matches revision [".($requestedRevision ?? 'unknown').'].',
            409,
            $errorCode,
            $previous,
        );
    }
}
