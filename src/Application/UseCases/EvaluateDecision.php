<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\UseCases;

use LogicException;
use Sysborg\LaravelJevai\Application\Pipeline\Call;
use Sysborg\LaravelJevai\Application\Pipeline\Pipeline;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Ports\Driven\DecisionGateway;

/**
 * Evaluates a decision (inline questions or saved judge) through the full pipeline.
 */
final readonly class EvaluateDecision
{
    /**
     * Create the use case.
     *
     * Example:
     * ```php
     * new EvaluateDecision($gateway, $pipeline);
     * ```
     *
     * @param  DecisionGateway  $gateway  Sends single attempts.
     * @param  Pipeline  $pipeline  Correlation, validation, redaction, events, usage, metrics, tracing, retries.
     */
    public function __construct(
        private DecisionGateway $gateway,
        private Pipeline $pipeline,
    ) {}

    /**
     * Evaluate the request.
     *
     * Example:
     * ```php
     * $result = $evaluate->execute($request);
     * $result = $evaluate->execute($request, $pendingCorrelationId); // queued calls
     * ```
     *
     * @param  DecisionRequest  $request  The request.
     * @param  CorrelationId|null  $correlationId  A preset id, or null to generate one.
     * @return DecisionResult The result, with total latency and attempts.
     *
     * @throws InvalidValue When the request breaks a Jev limit (nothing is sent).
     * @throws JevException When the call fails after the configured retries.
     */
    public function execute(DecisionRequest $request, ?CorrelationId $correlationId = null): DecisionResult
    {
        $result = $this->pipeline->run(
            Call::forDecision($request, $correlationId),
            fn (Call $call): DecisionResult => $this->gateway->decide($call->decisionRequest(), $call->correlationId()),
        );

        return $result instanceof DecisionResult
            ? $result
            : throw new LogicException('A pipeline stage replaced the decision result.');
    }
}
