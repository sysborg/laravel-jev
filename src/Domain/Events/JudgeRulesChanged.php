<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Events;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeRevisionMismatch;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;

/**
 * A call pinned to a judge revision was refused (409) because the judge's rules changed.
 *
 * Published right after the call's {@see DecisionFailed}. Review the new rules,
 * then update the pinned revision (or drop the pin to always use the latest).
 */
final readonly class JudgeRulesChanged implements JevEvent
{
    use DescribesCall;

    public const string NAME = 'jev.judge.rules_changed';

    /**
     * Create the event. Prefer {@see fromException()}.
     *
     * Example:
     * ```php
     * JudgeRulesChanged::fromException($id, $context, $now, $e, 'default');
     * ```
     *
     * @param  CorrelationId  $correlationId  Id of the refused call.
     * @param  Context  $context  Local data attached to the call.
     * @param  DateTimeImmutable  $occurredAt  When the call was refused.
     * @param  string  $judgeId  The judge whose rules changed.
     * @param  int|null  $pinnedRevision  The revision the call was pinned to.
     * @param  string  $message  Jev's error message.
     * @param  string|null  $connection  Package connection used for the call.
     */
    public function __construct(
        public CorrelationId $correlationId,
        public Context $context,
        public DateTimeImmutable $occurredAt,
        public string $judgeId,
        public ?int $pinnedRevision,
        public string $message,
        public ?string $connection = null,
    ) {}

    /**
     * Describe a 409 failure.
     *
     * Example:
     * ```php
     * $event = JudgeRulesChanged::fromException($id, $context, $now, $e);
     * $event->judgeId;        // 'judge_123'
     * $event->pinnedRevision; // 3
     * ```
     *
     * @param  CorrelationId  $correlationId  Id of the refused call.
     * @param  Context  $context  Local data attached to the call.
     * @param  DateTimeImmutable  $occurredAt  When the call was refused.
     * @param  JudgeRevisionMismatch  $exception  The 409 failure.
     * @param  string|null  $connection  Package connection used for the call.
     * @return self The event.
     */
    public static function fromException(
        CorrelationId $correlationId,
        Context $context,
        DateTimeImmutable $occurredAt,
        JudgeRevisionMismatch $exception,
        ?string $connection = null,
    ): self {
        return new self(
            $correlationId,
            $context,
            $occurredAt,
            $exception->judgeId,
            $exception->requestedRevision,
            $exception->getMessage(),
            $connection,
        );
    }

    /**
     * The event name.
     *
     * Example:
     * ```php
     * $event->name(); // 'jev.judge.rules_changed'
     * ```
     *
     * @return string Always {@see self::NAME}.
     */
    public function name(): string
    {
        return self::NAME;
    }
}
