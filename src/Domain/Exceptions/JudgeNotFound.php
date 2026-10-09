<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use Throwable;

/**
 * 404: the saved judge was deleted or is not accessible with this key.
 */
final class JudgeNotFound extends JevException
{
    /**
     * Create the exception with HTTP status 404.
     *
     * Example:
     * ```php
     * throw new JudgeNotFound('judge_123');
     * // "Jev judge [judge_123] was not found or is not accessible."
     * ```
     *
     * @param  string  $judgeId  Id of the judge that could not be found.
     * @param  string|null  $message  Custom message; a default naming the judge is used when null.
     * @param  string|null  $errorCode  `error.code` from Jev's error body, when present.
     * @param  Throwable|null  $previous  Underlying exception, e.g. the HTTP client error.
     */
    public function __construct(
        public readonly string $judgeId,
        ?string $message = null,
        ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?? "Jev judge [{$judgeId}] was not found or is not accessible.", 404, $errorCode, $previous);
    }
}
