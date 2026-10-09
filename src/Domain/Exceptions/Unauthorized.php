<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use Throwable;

/**
 * 401: the API key is missing, invalid or revoked.
 */
final class Unauthorized extends JevException
{
    /**
     * Create the exception with HTTP status 401.
     *
     * Example:
     * ```php
     * throw new Unauthorized(errorCode: 'invalid_key');
     * ```
     *
     * @param  string  $message  Human readable description. Must never contain the API key.
     * @param  string|null  $errorCode  `error.code` from Jev's error body, when present.
     * @param  Throwable|null  $previous  Underlying exception, e.g. the HTTP client error.
     */
    public function __construct(
        string $message = 'Jev rejected the API key.',
        ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 401, $errorCode, $previous);
    }
}
