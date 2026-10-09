<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for failures of a call to the Jev API.
 *
 * Every subclass tells the caller whether retrying is safe and whether
 * Jev may have charged for the failed call.
 */
abstract class JevException extends RuntimeException implements JevThrowable
{
    /**
     * Create the exception.
     *
     * Example:
     * ```php
     * throw new ApiError(500, 'Jev returned an error.', 'internal');
     * ```
     *
     * @param  string  $message  Human readable description. Must never contain the API key.
     * @param  int|null  $httpStatus  HTTP status returned by Jev, or null when no response arrived.
     * @param  string|null  $errorCode  `error.code` from Jev's error body, when present.
     * @param  Throwable|null  $previous  Underlying exception, e.g. the HTTP client error.
     */
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Whether repeating the same request may succeed and is safe to do.
     *
     * Example:
     * ```php
     * if ($e->isRetryable()) {
     *     retry(3, fn () => Jev::evaluate($request));
     * }
     * ```
     *
     * @return bool True when the request can be sent again without risk of double billing.
     */
    public function isRetryable(): bool
    {
        return false;
    }

    /**
     * Whether Jev may have charged for the call.
     *
     * When true, the outcome is uncertain and retrying risks paying twice.
     *
     * Example:
     * ```php
     * if ($e->mayHaveBeenBilled()) {
     *     Log::warning('Jev call outcome unknown, check usage before retrying.');
     * }
     * ```
     *
     * @return bool True when the call may have been executed and billed.
     */
    public function mayHaveBeenBilled(): bool
    {
        return false;
    }
}
