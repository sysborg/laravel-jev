<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Events;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;

/**
 * The circuit breaker closed again: a call succeeded after the circuit had opened.
 */
final readonly class CircuitClosed implements JevEvent
{
    use DescribesCall;

    public const string NAME = 'jev.circuit.closed';

    /**
     * Create the event.
     *
     * Example:
     * ```php
     * new CircuitClosed($id, $context, $now, 'default');
     * ```
     *
     * @param  CorrelationId  $correlationId  Id of the first successful call.
     * @param  Context  $context  Local data attached to that call.
     * @param  DateTimeImmutable  $occurredAt  When the circuit closed.
     * @param  string|null  $connection  The recovered connection.
     */
    public function __construct(
        public CorrelationId $correlationId,
        public Context $context,
        public DateTimeImmutable $occurredAt,
        public ?string $connection = null,
    ) {}

    /**
     * The event name.
     *
     * Example:
     * ```php
     * $event->name(); // 'jev.circuit.closed'
     * ```
     *
     * @return string Always {@see self::NAME}.
     */
    public function name(): string
    {
        return self::NAME;
    }
}
