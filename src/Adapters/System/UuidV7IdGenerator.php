<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\System;

use Ramsey\Uuid\Uuid;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Ports\Driven\IdGenerator;

/**
 * Time-ordered UUIDv7 correlation ids: unique, sortable and safe in headers.
 */
final class UuidV7IdGenerator implements IdGenerator
{
    /**
     * Create a new id.
     *
     * Example:
     * ```php
     * (new UuidV7IdGenerator)->correlationId(); // 0192f1c4-7a0e-7c3b-9a8e-1f2d3c4b5a69
     * ```
     *
     * @return CorrelationId The id.
     */
    public function correlationId(): CorrelationId
    {
        return new CorrelationId(Uuid::uuid7()->toString());
    }
}
