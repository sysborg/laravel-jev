<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Usage;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Support\Guard;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;

/**
 * One finished call, as stored for usage and cost accounting.
 */
final readonly class RunRecord
{
    /**
     * Create the record. Prefer the `for*()` named constructors.
     *
     * Example:
     * ```php
     * RunRecord::forDecision($request, $result, $clock->now());
     * ```
     *
     * @param  CorrelationId  $correlationId  Id linking the record to events, logs and spans.
     * @param  RunOperation  $operation  Which Jev operation was called.
     * @param  RunStatus  $status  Final outcome, after retries.
     * @param  DateTimeImmutable  $occurredAt  When the call finished.
     * @param  Usage  $usage  Tokens consumed (none for failures).
     * @param  Billing  $billing  What was charged (unknown for failures).
     * @param  int  $latencyMs  Wall time of the whole call in milliseconds, retries included.
     * @param  int  $attempts  Number of HTTP attempts, at least 1.
     * @param  Context  $context  Local data attached to the call.
     * @param  string|null  $model  Model that answered, or the requested one for failures.
     * @param  string|null  $connection  Package connection name.
     * @param  string|null  $runId  `X-Jev-Run-Id`, when sent.
     * @param  string|null  $judgeId  Saved judge id, for judge calls.
     * @param  int|null  $judgeRevision  Saved judge revision, for judge calls.
     * @param  string|null  $sessionId  Jev `session_id` label.
     * @param  string|null  $user  Jev `user` label.
     * @param  int|null  $httpStatus  Final HTTP status; null when no response arrived.
     * @param  string|null  $errorCode  Jev `error.code` of a failure.
     * @param  bool  $billingUncertain  True when a failed call may still have been billed.
     * @param  array<string, mixed>|null  $payload  Answers or raw body, stored only when configured.
     *
     * @throws InvalidValue When the latency is negative or attempts is lower than 1.
     */
    public function __construct(
        public CorrelationId $correlationId,
        public RunOperation $operation,
        public RunStatus $status,
        public DateTimeImmutable $occurredAt,
        public Usage $usage,
        public Billing $billing,
        public int $latencyMs,
        public int $attempts,
        public Context $context,
        public ?string $model = null,
        public ?string $connection = null,
        public ?string $runId = null,
        public ?string $judgeId = null,
        public ?int $judgeRevision = null,
        public ?string $sessionId = null,
        public ?string $user = null,
        public ?int $httpStatus = null,
        public ?string $errorCode = null,
        public bool $billingUncertain = false,
        public ?array $payload = null,
    ) {
        Guard::nonNegative($latencyMs, 'run record latency');

        if ($attempts < 1) {
            throw InvalidValue::because('run record attempts', 'must be at least 1');
        }
    }

    /**
     * Record a successful decision (inline questions or saved judge).
     *
     * Example:
     * ```php
     * $repository->record(RunRecord::forDecision($request, $result, $clock->now()));
     * ```
     *
     * @param  DecisionRequest  $request  The request that was sent.
     * @param  DecisionResult  $result  What Jev returned.
     * @param  DateTimeImmutable  $occurredAt  When the call finished.
     * @param  array<string, mixed>|null  $payload  Answers or raw body to store, when configured.
     * @return self The record.
     */
    public static function forDecision(
        DecisionRequest $request,
        DecisionResult $result,
        DateTimeImmutable $occurredAt,
        ?array $payload = null,
    ): self {
        $meta = $result->meta;

        return new self(
            correlationId: $meta->correlationId,
            operation: $request->isJudgeCall() ? RunOperation::Judge : RunOperation::Decision,
            status: RunStatus::Succeeded,
            occurredAt: $occurredAt,
            usage: $result->usage,
            billing: $result->billing,
            latencyMs: $meta->latencyMs,
            attempts: $meta->attempts,
            context: $request->context,
            model: $meta->model,
            connection: $meta->connection,
            runId: $meta->runId,
            judgeId: $meta->judgeId ?? $request->judge?->id,
            judgeRevision: $meta->judgeRevision ?? $request->judge?->revision,
            sessionId: $request->sessionId,
            user: $request->user,
            httpStatus: 200,
            payload: $payload,
        );
    }

    /**
     * Record a successful web-context call.
     *
     * Example:
     * ```php
     * $repository->record(RunRecord::forWebContext($request, $result, $clock->now()));
     * ```
     *
     * @param  WebContextRequest  $request  The request that was sent.
     * @param  WebContextResult  $result  What Jev returned.
     * @param  DateTimeImmutable  $occurredAt  When the call finished.
     * @param  array<string, mixed>|null  $payload  Decision or raw body to store, when configured.
     * @return self The record.
     */
    public static function forWebContext(
        WebContextRequest $request,
        WebContextResult $result,
        DateTimeImmutable $occurredAt,
        ?array $payload = null,
    ): self {
        $meta = $result->meta;

        return new self(
            correlationId: $meta->correlationId,
            operation: RunOperation::WebContext,
            status: RunStatus::Succeeded,
            occurredAt: $occurredAt,
            usage: $result->usage->toUsage(),
            billing: $result->billing,
            latencyMs: $meta->latencyMs,
            attempts: $meta->attempts,
            context: $request->context,
            model: $meta->model,
            connection: $meta->connection,
            runId: $meta->runId,
            httpStatus: 200,
            payload: $payload,
        );
    }

    /**
     * Record a call that failed after all retries.
     *
     * Example:
     * ```php
     * $repository->record(RunRecord::forFailure(
     *     RunOperation::Decision, $correlationId, $exception, $clock->now(),
     *     latencyMs: 30_000, attempts: 1, context: $request->context, model: 'jev-latest',
     * ));
     * ```
     *
     * @param  RunOperation  $operation  Which Jev operation was called.
     * @param  CorrelationId  $correlationId  Id of the call.
     * @param  JevException  $error  The final failure.
     * @param  DateTimeImmutable  $occurredAt  When the call gave up.
     * @param  int  $latencyMs  Wall time of the whole call in milliseconds, retries included.
     * @param  int  $attempts  Number of HTTP attempts, at least 1.
     * @param  Context  $context  Local data attached to the call.
     * @param  string|null  $model  Requested model.
     * @param  string|null  $connection  Package connection name.
     * @param  string|null  $sessionId  Jev `session_id` label.
     * @param  string|null  $user  Jev `user` label.
     * @return self The record, with no usage, unknown billing and the error's status and code.
     *
     * @throws InvalidValue When the latency is negative or attempts is lower than 1.
     */
    public static function forFailure(
        RunOperation $operation,
        CorrelationId $correlationId,
        JevException $error,
        DateTimeImmutable $occurredAt,
        int $latencyMs,
        int $attempts,
        Context $context,
        ?string $model = null,
        ?string $connection = null,
        ?string $sessionId = null,
        ?string $user = null,
    ): self {
        return new self(
            correlationId: $correlationId,
            operation: $operation,
            status: RunStatus::Failed,
            occurredAt: $occurredAt,
            usage: Usage::none(),
            billing: Billing::unknown(),
            latencyMs: $latencyMs,
            attempts: $attempts,
            context: $context,
            model: $model,
            connection: $connection,
            sessionId: $sessionId,
            user: $user,
            httpStatus: $error->httpStatus,
            errorCode: $error->errorCode,
            billingUncertain: $error->mayHaveBeenBilled(),
        );
    }

    /**
     * Whether the call succeeded.
     *
     * Example:
     * ```php
     * $record->succeeded(); // true
     * ```
     *
     * @return bool True for {@see RunStatus::Succeeded}.
     */
    public function succeeded(): bool
    {
        return $this->status === RunStatus::Succeeded;
    }
}
