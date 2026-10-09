<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Events;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Exceptions\InsufficientCredits;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;

/**
 * Jev refused a call (402) because neither paid input tokens nor credits can cover it.
 *
 * Published right after the call's {@see DecisionFailed}; the call was not charged.
 * Every following call will fail the same way until the account is topped up.
 *
 * @api
 */
final readonly class CreditsExhausted implements JevEvent
{
    use DescribesCall;

    public const string NAME = 'jev.credits.exhausted';

    /**
     * Create the event. Prefer {@see fromException()}.
     *
     * Example:
     * ```php
     * CreditsExhausted::fromException(RunOperation::Decision, $id, $context, $now, $e, 'jev-latest', 'default');
     * ```
     *
     * @param  RunOperation  $operation  Which Jev operation was refused.
     * @param  CorrelationId  $correlationId  Id of the refused call.
     * @param  Context  $context  Local data attached to the call.
     * @param  DateTimeImmutable  $occurredAt  When the call was refused.
     * @param  string  $message  Jev's error message.
     * @param  string|null  $errorCode  Jev `error.code`, when sent.
     * @param  string|null  $model  Model of the call.
     * @param  string|null  $connection  Package connection whose account is empty.
     */
    public function __construct(
        public RunOperation $operation,
        public CorrelationId $correlationId,
        public Context $context,
        public DateTimeImmutable $occurredAt,
        public string $message,
        public ?string $errorCode = null,
        public ?string $model = null,
        public ?string $connection = null,
    ) {}

    /**
     * Describe a 402 failure.
     *
     * Example:
     * ```php
     * $event = CreditsExhausted::fromException(RunOperation::Judge, $id, $context, $now, $e);
     * ```
     *
     * @param  RunOperation  $operation  Which Jev operation was refused.
     * @param  CorrelationId  $correlationId  Id of the refused call.
     * @param  Context  $context  Local data attached to the call.
     * @param  DateTimeImmutable  $occurredAt  When the call was refused.
     * @param  InsufficientCredits  $exception  The 402 failure.
     * @param  string|null  $model  Model of the call.
     * @param  string|null  $connection  Package connection whose account is empty.
     * @return self The event.
     */
    public static function fromException(
        RunOperation $operation,
        CorrelationId $correlationId,
        Context $context,
        DateTimeImmutable $occurredAt,
        InsufficientCredits $exception,
        ?string $model = null,
        ?string $connection = null,
    ): self {
        return new self($operation, $correlationId, $context, $occurredAt, $exception->getMessage(), $exception->errorCode, $model, $connection);
    }

    /**
     * The event name.
     *
     * Example:
     * ```php
     * $event->name(); // 'jev.credits.exhausted'
     * ```
     *
     * @return string Always {@see self::NAME}.
     */
    public function name(): string
    {
        return self::NAME;
    }
}
