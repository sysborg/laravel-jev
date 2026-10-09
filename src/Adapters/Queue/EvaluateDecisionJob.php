<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Sysborg\LaravelJevai\Application\UseCases\EvaluateDecision;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;

/**
 * Evaluates a queued decision. The result is delivered through events.
 *
 * Serialized payload: the request and the correlation id only. The API key
 * is resolved from config when the job runs and is never serialized.
 */
final class EvaluateDecisionJob implements ShouldQueue
{
    use Queueable;

    /**
     * Run at most once: Jev has no idempotency key, so a retried job could be billed twice.
     * Retryable failures are already retried inside the call by the retry policy.
     */
    public int $tries = 1;

    /**
     * Create the job.
     *
     * Example:
     * ```php
     * dispatch(new EvaluateDecisionJob($request, $correlationId));
     * ```
     *
     * @param  DecisionRequest  $request  The request to evaluate.
     * @param  CorrelationId  $correlationId  Id the evaluation and its events use.
     */
    public function __construct(
        public readonly DecisionRequest $request,
        public readonly CorrelationId $correlationId,
    ) {}

    /**
     * Evaluate the decision.
     *
     * A {@see JevException} is swallowed: it was already published as a
     * `DecisionFailed` event and recorded, and failing the job would only invite a retry.
     *
     * Example:
     * ```php
     * $job->handle(app(EvaluateDecision::class));
     * ```
     *
     * @param  EvaluateDecision  $evaluate  The decision use case.
     * @return void Nothing.
     *
     * @throws InvalidValue When the request breaks a Jev limit (nothing was sent).
     */
    public function handle(EvaluateDecision $evaluate): void
    {
        try {
            $evaluate->execute($this->request, $this->correlationId);
        } catch (JevException) {
            // Already published as DecisionFailed and recorded by the pipeline.
        }
    }
}
