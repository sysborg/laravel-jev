<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use Throwable;

/**
 * 422 (or 400): Jev rejected the request body. Not charged.
 */
final class InvalidRequest extends JevException
{
    /**
     * Create the exception, with HTTP status 422 by default.
     *
     * Example:
     * ```php
     * throw new InvalidRequest('Unknown field "foo".', 422, 'invalid_request');
     * ```
     *
     * @param  string  $message  Human readable description, usually Jev's `error.message`.
     * @param  int  $httpStatus  HTTP status returned by Jev (422, or 400 for e.g. a non-null `provider`).
     * @param  string|null  $errorCode  `error.code` from Jev's error body, when present.
     * @param  Throwable|null  $previous  Underlying exception, e.g. the HTTP client error.
     */
    public function __construct(
        string $message = 'Jev rejected the request as invalid.',
        int $httpStatus = 422,
        ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $errorCode, $previous);
    }
}
