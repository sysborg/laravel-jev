<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use Throwable;

/**
 * 502 / 503 / 504: the model provider behind Jev failed. Not billed.
 *
 * @api
 */
final class UpstreamFailure extends JevException
{
    /**
     * Create the exception for an upstream status.
     *
     * Example:
     * ```php
     * throw new UpstreamFailure(504);
     * ```
     *
     * @param  int  $httpStatus  HTTP status returned by Jev (502, 503 or 504).
     * @param  string  $message  Human readable description.
     * @param  string|null  $errorCode  `error.code` from Jev's error body, when present.
     * @param  Throwable|null  $previous  Underlying exception, e.g. the HTTP client error.
     */
    public function __construct(
        int $httpStatus,
        string $message = 'Jev upstream failure.',
        ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $errorCode, $previous);
    }

    /**
     * Upstream failures are not billed, so they are safe to retry with backoff.
     *
     * Example:
     * ```php
     * $e->isRetryable(); // true
     * ```
     *
     * @return bool Always true.
     */
    public function isRetryable(): bool
    {
        return true;
    }
}
