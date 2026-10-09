<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Events;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;

/**
 * Something that happened during a Jev call, published to the application.
 *
 * Implementations must be serializable (scalars, arrays and domain value
 * objects only) so they can be handled by queued listeners.
 *
 * @api
 */
interface JevEvent
{
    /**
     * Stable dotted name of the event, for logs and metrics.
     *
     * Example:
     * ```php
     * $event->name(); // 'jev.decision.succeeded'
     * ```
     *
     * @return string The event name.
     */
    public function name(): string;

    /**
     * The id of the call this event belongs to.
     *
     * Example:
     * ```php
     * if ($event->correlationId()->equals($pending->correlationId)) {
     *     // our queued decision finished
     * }
     * ```
     *
     * @return CorrelationId The correlation id.
     */
    public function correlationId(): CorrelationId;

    /**
     * Application data attached to the call.
     *
     * Example:
     * ```php
     * Ticket::find($event->context()->get('ticket_id'));
     * ```
     *
     * @return Context The local context, never sent to Jev.
     */
    public function context(): Context;

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
    public function occurredAt(): DateTimeImmutable;
}
