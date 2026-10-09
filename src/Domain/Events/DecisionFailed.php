<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Events;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;

/**
 * A call failed after all retries (any operation, including web context).
 *
 * Holds a serializable description of the exception, not the exception itself.
 */
final readonly class DecisionFailed implements JevEvent
{
    use DescribesCall;

    public const string NAME = 'jev.decision.failed';

    /**
     * Create the event. Prefer {@see fromException()}.
     *
     * Example:
     * ```php
     * DecisionFailed::fromException(RunOperation::Decision, $id, $context, $now, $e, attempts: 3, latencyMs: 2100);
     * ```
     *
     * @param  RunOperation  $operation  Which Jev operation was called.
     * @param  CorrelationId  $correlationId  Id of the call.
     * @param  Context  $context  Local data attached to the call.
     * @param  DateTimeImmutable  $occurredAt  When the call gave up.
     * @param  class-string<JevException>  $exception  Class of the final exception.
     * @param  string  $message  Its message (never contains the API key).
     * @param  int|null  $httpStatus  Final HTTP status; null when no response arrived.
     * @param  string|null  $errorCode  Jev `error.code`, when sent.
     * @param  bool  $retryable  Whether the failure type is retryable in principle.
     * @param  bool  $mayHaveBeenBilled  Whether Jev may still have charged for the call.
     * @param  int  $attempts  HTTP attempts made.
     * @param  int  $latencyMs  Wall time of the whole call, retries included.
     * @param  string|null  $model  Model of the call: the requested one, or the connection default.
     * @param  string|null  $connection  Package connection used for the call.
     */
    public function __construct(
        public RunOperation $operation,
        public CorrelationId $correlationId,
        public Context $context,
        public DateTimeImmutable $occurredAt,
        public string $exception,
        public string $message,
        public ?int $httpStatus,
        public ?string $errorCode,
        public bool $retryable,
        public bool $mayHaveBeenBilled,
        public int $attempts,
        public int $latencyMs,
        public ?string $model = null,
        public ?string $connection = null,
    ) {}

    /**
     * Describe a final {@see JevException}.
     *
     * Example:
     * ```php
     * $event = DecisionFailed::fromException(RunOperation::Judge, $id, $context, $now, $e, 1, 340, 'jev-latest');
     * ```
     *
     * @param  RunOperation  $operation  Which Jev operation was called.
     * @param  CorrelationId  $correlationId  Id of the call.
     * @param  Context  $context  Local data attached to the call.
     * @param  DateTimeImmutable  $occurredAt  When the call gave up.
     * @param  JevException  $exception  The final failure.
     * @param  int  $attempts  HTTP attempts made.
     * @param  int  $latencyMs  Wall time of the whole call, retries included.
     * @param  string|null  $model  Model of the call.
     * @param  string|null  $connection  Package connection used for the call.
     * @return self The event.
     */
    public static function fromException(
        RunOperation $operation,
        CorrelationId $correlationId,
        Context $context,
        DateTimeImmutable $occurredAt,
        JevException $exception,
        int $attempts,
        int $latencyMs,
        ?string $model = null,
        ?string $connection = null,
    ): self {
        return new self(
            $operation,
            $correlationId,
            $context,
            $occurredAt,
            $exception::class,
            $exception->getMessage(),
            $exception->httpStatus,
            $exception->errorCode,
            $exception->isRetryable(),
            $exception->mayHaveBeenBilled(),
            $attempts,
            $latencyMs,
            $model,
            $connection,
        );
    }

    /**
     * The event name.
     *
     * Example:
     * ```php
     * $event->name(); // 'jev.decision.failed'
     * ```
     *
     * @return string Always {@see self::NAME}.
     */
    public function name(): string
    {
        return self::NAME;
    }
}
