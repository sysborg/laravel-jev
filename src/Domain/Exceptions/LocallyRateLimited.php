<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

/**
 * The package's own rate limiter refused the attempt before it was sent. Not billed.
 */
final class LocallyRateLimited extends JevException
{
    /**
     * Create the exception.
     *
     * Example:
     * ```php
     * throw new LocallyRateLimited(retryAfterSeconds: 12, limitPerMinute: 1000);
     * ```
     *
     * @param  int  $retryAfterSeconds  Seconds until the current window ends.
     * @param  int  $limitPerMinute  The configured limit.
     */
    public function __construct(
        public readonly int $retryAfterSeconds,
        public readonly int $limitPerMinute,
    ) {
        parent::__construct("Jev client-side rate limit of {$limitPerMinute} requests per minute reached; retry in {$retryAfterSeconds}s.");
    }
}
