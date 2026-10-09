<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Events;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Exceptions\RateLimited;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;

/**
 * An attempt failed with a retryable error; another attempt follows after a delay.
 */
final readonly class RetryScheduled implements JevEvent
{
    use DescribesCall;

    public const string NAME = 'jev.retry.scheduled';

    /**
     * Create the event. Prefer {@see fromException()}.
     *
     * Example:
     * ```php
     * RetryScheduled::fromException(RunOperation::Decision, $id, $context, $now, $e, failedAttempt: 1, delayMs: 400);
     * ```
     *
     * @param  RunOperation  $operation  Which Jev operation is called.
     * @param  CorrelationId  $correlationId  Id of the call.
     * @param  Context  $context  Local data attached to the call.
     * @param  DateTimeImmutable  $occurredAt  When the retry was scheduled.
     * @param  int  $failedAttempt  Number of the attempt that just failed, starting at 1.
     * @param  int  $delayMs  Wait before the next attempt.
     * @param  class-string<JevException>  $reason  Class of the exception that caused the retry.
     * @param  int|null  $httpStatus  Status of the failed attempt; null when no response arrived.
     * @param  int|null  $retryAfterSeconds  `Retry-After` sent with a 429, when present.
     * @param  string|null  $model  Model of the call: the requested one, or the connection default.
     * @param  string|null  $connection  Package connection used for the call.
     */
    public function __construct(
        public RunOperation $operation,
        public CorrelationId $correlationId,
        public Context $context,
        public DateTimeImmutable $occurredAt,
        public int $failedAttempt,
        public int $delayMs,
        public string $reason,
        public ?int $httpStatus = null,
        public ?int $retryAfterSeconds = null,
        public ?string $model = null,
        public ?string $connection = null,
    ) {}

    /**
     * Describe the failure that triggers a retry.
     *
     * Example:
     * ```php
     * $event = RetryScheduled::fromException(RunOperation::Decision, $id, $context, $now, $e, 1, 2000);
     * ```
     *
     * @param  RunOperation  $operation  Which Jev operation is called.
     * @param  CorrelationId  $correlationId  Id of the call.
     * @param  Context  $context  Local data attached to the call.
     * @param  DateTimeImmutable  $occurredAt  When the retry was scheduled.
     * @param  JevException  $exception  The failure of the attempt.
     * @param  int  $failedAttempt  Number of the attempt that just failed, starting at 1.
     * @param  int  $delayMs  Wait before the next attempt.
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
        int $failedAttempt,
        int $delayMs,
        ?string $model = null,
        ?string $connection = null,
    ): self {
        return new self(
            $operation,
            $correlationId,
            $context,
            $occurredAt,
            $failedAttempt,
            $delayMs,
            $exception::class,
            $exception->httpStatus,
            $exception instanceof RateLimited ? $exception->retryAfterSeconds : null,
            $model,
            $connection,
        );
    }

    /**
     * Whether the retry follows a rate limit (429).
     *
     * Example:
     * ```php
     * if ($event->wasRateLimited()) {
     *     Log::notice('Jev rate limit reached.');
     * }
     * ```
     *
     * @return bool True when the failed attempt was rate limited.
     */
    public function wasRateLimited(): bool
    {
        return $this->reason === RateLimited::class;
    }

    /**
     * The event name.
     *
     * Example:
     * ```php
     * $event->name(); // 'jev.retry.scheduled'
     * ```
     *
     * @return string Always {@see self::NAME}.
     */
    public function name(): string
    {
        return self::NAME;
    }
}
