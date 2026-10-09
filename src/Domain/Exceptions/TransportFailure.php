<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use Throwable;

/**
 * The request may or may not have reached Jev (timeout, dropped connection).
 *
 * Jev has no idempotency key, so the outcome is uncertain: the call may have
 * been executed and billed. It is not retryable by default.
 */
final class TransportFailure extends JevException
{
    /**
     * Create the exception; there is no HTTP status because no response arrived.
     *
     * Example:
     * ```php
     * throw new TransportFailure('Timed out after 30 seconds.', $connectionException);
     * ```
     *
     * @param  string  $message  Human readable description.
     * @param  Throwable|null  $previous  Underlying exception, e.g. the connection error.
     */
    public function __construct(
        string $message = 'The connection to Jev failed before a response was received.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, null, null, $previous);
    }

    /**
     * The request may have been executed before the connection failed.
     *
     * Example:
     * ```php
     * $e->mayHaveBeenBilled(); // true
     * ```
     *
     * @return bool Always true.
     */
    public function mayHaveBeenBilled(): bool
    {
        return true;
    }
}
