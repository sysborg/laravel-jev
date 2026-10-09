<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use Throwable;

/**
 * 402: neither paid input tokens nor credits can cover the request. Not charged.
 *
 * @api
 */
final class InsufficientCredits extends JevException
{
    /**
     * Create the exception with HTTP status 402.
     *
     * Example:
     * ```php
     * throw new InsufficientCredits;
     * ```
     *
     * @param  string  $message  Human readable description.
     * @param  string|null  $errorCode  `error.code` from Jev's error body, when present.
     * @param  Throwable|null  $previous  Underlying exception, e.g. the HTTP client error.
     */
    public function __construct(
        string $message = 'The Jev account has no tokens or credits left to cover this request.',
        ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 402, $errorCode, $previous);
    }
}
