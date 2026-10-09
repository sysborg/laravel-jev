<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

/**
 * The circuit breaker is open after repeated upstream failures: the call fails fast
 * without being sent. Not billed.
 */
final class CircuitOpen extends JevException
{
    /**
     * Create the exception.
     *
     * Example:
     * ```php
     * throw new CircuitOpen(retryAfterSeconds: 18, connection: 'default');
     * ```
     *
     * @param  int  $retryAfterSeconds  Seconds until the circuit lets calls through again.
     * @param  string|null  $connection  The connection whose circuit is open.
     */
    public function __construct(
        public readonly int $retryAfterSeconds,
        public readonly ?string $connection = null,
    ) {
        parent::__construct('Jev circuit breaker is open for connection ['.($connection ?? 'default')."] after repeated upstream failures; retry in {$retryAfterSeconds}s.");
    }
}
