<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Events;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;

/**
 * A decision (inline questions or saved judge) succeeded. Carries the full result.
 *
 * @api
 */
final readonly class DecisionSucceeded implements JevEvent
{
    use DescribesCall;

    public const string NAME = 'jev.decision.succeeded';

    public CorrelationId $correlationId;

    /**
     * Create the event.
     *
     * Example:
     * ```php
     * new DecisionSucceeded(RunOperation::Decision, $result, $request->context, $now);
     * ```
     *
     * @param  RunOperation  $operation  {@see RunOperation::Decision} or {@see RunOperation::Judge}.
     * @param  DecisionResult  $result  Answers, usage, billing and metadata; `raw` only when configured.
     * @param  Context  $context  Local data attached to the call.
     * @param  DateTimeImmutable  $occurredAt  When the call finished.
     */
    public function __construct(
        public RunOperation $operation,
        public DecisionResult $result,
        public Context $context,
        public DateTimeImmutable $occurredAt,
    ) {
        $this->correlationId = $result->meta->correlationId;
    }

    /**
     * The event name.
     *
     * Example:
     * ```php
     * $event->name(); // 'jev.decision.succeeded'
     * ```
     *
     * @return string Always {@see self::NAME}.
     */
    public function name(): string
    {
        return self::NAME;
    }
}
