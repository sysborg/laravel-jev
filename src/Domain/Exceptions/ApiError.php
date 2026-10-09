<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use Throwable;

/**
 * Any other error status returned by Jev.
 */
final class ApiError extends JevException
{
    /**
     * Create the exception for an unmapped error status.
     *
     * Example:
     * ```php
     * throw new ApiError(500, 'Internal error.', 'internal');
     * ```
     *
     * @param  int  $httpStatus  HTTP status returned by Jev.
     * @param  string  $message  Human readable description, usually Jev's `error.message`.
     * @param  string|null  $errorCode  `error.code` from Jev's error body, when present.
     * @param  Throwable|null  $previous  Underlying exception, e.g. the HTTP client error.
     */
    public function __construct(
        int $httpStatus,
        string $message = 'Jev returned an error.',
        ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $errorCode, $previous);
    }
}
