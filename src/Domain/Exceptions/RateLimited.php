<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use Throwable;

/**
 * 429: too many requests. Honor {@see $retryAfterSeconds} before retrying.
 */
final class RateLimited extends JevException
{
    /**
     * Create the exception with HTTP status 429.
     *
     * Example:
     * ```php
     * throw new RateLimited(retryAfterSeconds: 30);
     * ```
     *
     * @param  int|null  $retryAfterSeconds  Value of the `Retry-After` header, when sent.
     * @param  string  $message  Human readable description.
     * @param  string|null  $errorCode  `error.code` from Jev's error body, when present.
     * @param  Throwable|null  $previous  Underlying exception, e.g. the HTTP client error.
     */
    public function __construct(
        public readonly ?int $retryAfterSeconds = null,
        string $message = 'Jev rate limit exceeded.',
        ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 429, $errorCode, $previous);
    }

    /**
     * Rate limited calls were not executed, so they are safe to retry after waiting.
     *
     * Example:
     * ```php
     * sleep($e->retryAfterSeconds ?? 1);
     * ```
     *
     * @return bool Always true.
     */
    public function isRetryable(): bool
    {
        return true;
    }
}
