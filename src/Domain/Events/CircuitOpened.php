<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Events;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;

/**
 * The circuit breaker opened: calls on this connection fail fast for a while.
 *
 * @api
 */
final readonly class CircuitOpened implements JevEvent
{
    use DescribesCall;

    public const string NAME = 'jev.circuit.opened';

    /**
     * Create the event.
     *
     * Example:
     * ```php
     * new CircuitOpened($id, $context, $now, consecutiveFailures: 5, openSeconds: 30, connection: 'default');
     * ```
     *
     * @param  CorrelationId  $correlationId  Id of the call whose failure opened the circuit.
     * @param  Context  $context  Local data attached to that call.
     * @param  DateTimeImmutable  $occurredAt  When the circuit opened.
     * @param  int  $consecutiveFailures  Upstream failures in a row that triggered it.
     * @param  int  $openSeconds  How long calls fail fast.
     * @param  string|null  $connection  The affected connection.
     */
    public function __construct(
        public CorrelationId $correlationId,
        public Context $context,
        public DateTimeImmutable $occurredAt,
        public int $consecutiveFailures,
        public int $openSeconds,
        public ?string $connection = null,
    ) {}

    /**
     * The event name.
     *
     * Example:
     * ```php
     * $event->name(); // 'jev.circuit.opened'
     * ```
     *
     * @return string Always {@see self::NAME}.
     */
    public function name(): string
    {
        return self::NAME;
    }
}
