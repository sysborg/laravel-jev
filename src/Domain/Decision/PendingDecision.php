<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Decision;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;

/**
 * Receipt of a decision queued for background evaluation.
 *
 * The result arrives through events carrying the same correlation id.
 */
final readonly class PendingDecision
{
    /**
     * Create the receipt.
     *
     * Example:
     * ```php
     * new PendingDecision(new CorrelationId('c-1'), new DateTimeImmutable);
     * ```
     *
     * @param  CorrelationId  $correlationId  Id carried by every event of the call.
     * @param  DateTimeImmutable  $queuedAt  When the job was dispatched.
     * @param  Context  $context  Local data attached to the call and its events.
     */
    public function __construct(
        public CorrelationId $correlationId,
        public DateTimeImmutable $queuedAt,
        public Context $context,
    ) {}
}
