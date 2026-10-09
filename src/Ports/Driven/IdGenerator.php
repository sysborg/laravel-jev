<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Ports\Driven;

use Sysborg\LaravelJevai\Domain\Run\CorrelationId;

/**
 * Creates correlation ids, replaceable in tests for predictable values.
 */
interface IdGenerator
{
    /**
     * Create a new, unique correlation id (UUIDv7 in production).
     *
     * Example:
     * ```php
     * $correlationId = $ids->correlationId(); // 0192f1c4-7a0e-7c3b-9a8e-1f2d3c4b5a69
     * ```
     *
     * @return CorrelationId The new id.
     */
    public function correlationId(): CorrelationId;
}
