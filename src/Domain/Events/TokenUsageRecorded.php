<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Events;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Usage\Billing;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;
use Sysborg\LaravelJevai\Domain\Usage\Usage;

/**
 * Tokens consumed and charges of one successful call, for cost tracking.
 *
 * @api
 */
final readonly class TokenUsageRecorded implements JevEvent
{
    use DescribesCall;

    public const string NAME = 'jev.usage.recorded';

    /**
     * Create the event.
     *
     * Example:
     * ```php
     * new TokenUsageRecorded(RunOperation::Decision, $id, $context, $now, 'jev-latest', 'default',
     *     $result->usage, $result->billing);
     * ```
     *
     * @param  RunOperation  $operation  Which Jev operation was called.
     * @param  CorrelationId  $correlationId  Id of the call.
     * @param  Context  $context  Local data attached to the call.
     * @param  DateTimeImmutable  $occurredAt  When the call finished.
     * @param  string  $model  Model that answered.
     * @param  string|null  $connection  Package connection used.
     * @param  Usage  $usage  Tokens consumed.
     * @param  Billing  $billing  What was charged and what is left.
     */
    public function __construct(
        public RunOperation $operation,
        public CorrelationId $correlationId,
        public Context $context,
        public DateTimeImmutable $occurredAt,
        public string $model,
        public ?string $connection,
        public Usage $usage,
        public Billing $billing,
    ) {}

    /**
     * The event name.
     *
     * Example:
     * ```php
     * $event->name(); // 'jev.usage.recorded'
     * ```
     *
     * @return string Always {@see self::NAME}.
     */
    public function name(): string
    {
        return self::NAME;
    }
}
