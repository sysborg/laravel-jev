<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Events;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;

/**
 * {@see JevEvent} accessors for events with `correlationId`, `context` and `occurredAt` properties.
 */
trait DescribesCall
{
    /**
     * The id of the call this event belongs to.
     *
     * Example:
     * ```php
     * $event->correlationId()->equals($pending->correlationId);
     * ```
     *
     * @return CorrelationId The correlation id.
     */
    public function correlationId(): CorrelationId
    {
        return $this->correlationId;
    }

    /**
     * Application data attached to the call.
     *
     * Example:
     * ```php
     * $event->context()->get('ticket_id'); // 42
     * ```
     *
     * @return Context The local context, never sent to Jev.
     */
    public function context(): Context
    {
        return $this->context;
    }

    /**
     * When the event happened.
     *
     * Example:
     * ```php
     * $event->occurredAt()->format(DATE_ATOM);
     * ```
     *
     * @return DateTimeImmutable The time of the event.
     */
    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
