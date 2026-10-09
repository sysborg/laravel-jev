<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline;

use LogicException;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;

/**
 * State of one Jev call while it travels through the pipeline.
 *
 * Stages may replace the request (e.g. to inject the correlation id into the
 * trace) and fill in what they learn (correlation id, start time, attempts).
 */
final class Call
{
    /** Set by the Correlate stage. */
    public ?CorrelationId $correlationId;

    /** Monotonic start time in milliseconds, set by the Correlate stage. */
    public float $startedAtMs = 0.0;

    /** HTTP attempts made so far, counted by the Retry stage. */
    public int $attempts = 0;

    /** Redacted state (or web question) for events, logs and spans; set by the Redact stage. */
    public ?string $statePreview = null;

    /** Package connection used for the call, set by the Correlate stage. */
    public ?string $connection = null;

    /** Model used when the request names none (the connection default), set by the Correlate stage. */
    public ?string $defaultModel = null;

    /**
     * Create the call. Use {@see forDecision()} or {@see forWebContext()}.
     *
     * Example:
     * ```php
     * Call::forDecision($request);
     * ```
     *
     * @param  RunOperation  $operation  Which Jev operation is called.
     * @param  DecisionRequest|WebContextRequest  $request  The request, as it will be sent.
     * @param  CorrelationId|null  $correlationId  A preset id (queued calls), or null to generate one.
     */
    private function __construct(
        public readonly RunOperation $operation,
        public DecisionRequest|WebContextRequest $request,
        ?CorrelationId $correlationId,
    ) {
        $this->correlationId = $correlationId;
    }

    /**
     * Start a decision call (inline questions or saved judge).
     *
     * Example:
     * ```php
     * $call = Call::forDecision($request);                        // new correlation id
     * $call = Call::forDecision($request, new CorrelationId('c')); // queued call
     * ```
     *
     * @param  DecisionRequest  $request  The request.
     * @param  CorrelationId|null  $correlationId  A preset id, or null to generate one.
     * @return self The call.
     */
    public static function forDecision(DecisionRequest $request, ?CorrelationId $correlationId = null): self
    {
        return new self(
            $request->isJudgeCall() ? RunOperation::Judge : RunOperation::Decision,
            $request,
            $correlationId,
        );
    }

    /**
     * Start a web-context call.
     *
     * Example:
     * ```php
     * $call = Call::forWebContext(WebContextRequest::ask('Q?'));
     * ```
     *
     * @param  WebContextRequest  $request  The request.
     * @param  CorrelationId|null  $correlationId  A preset id, or null to generate one.
     * @return self The call.
     */
    public static function forWebContext(WebContextRequest $request, ?CorrelationId $correlationId = null): self
    {
        return new self(RunOperation::WebContext, $request, $correlationId);
    }

    /**
     * The correlation id, once the Correlate stage has run.
     *
     * Example:
     * ```php
     * $gateway->decide($call->decisionRequest(), $call->correlationId());
     * ```
     *
     * @return CorrelationId The id.
     *
     * @throws LogicException When called before the Correlate stage.
     */
    public function correlationId(): CorrelationId
    {
        return $this->correlationId ?? throw new LogicException('The call has no correlation id yet; the Correlate stage must run first.');
    }

    /**
     * The request of a decision call.
     *
     * Example:
     * ```php
     * $call->decisionRequest()->questions;
     * ```
     *
     * @return DecisionRequest The request.
     *
     * @throws LogicException When this is a web-context call.
     */
    public function decisionRequest(): DecisionRequest
    {
        return $this->request instanceof DecisionRequest
            ? $this->request
            : throw new LogicException('This call is not a decision call.');
    }

    /**
     * The request of a web-context call.
     *
     * Example:
     * ```php
     * $call->webContextRequest()->question;
     * ```
     *
     * @return WebContextRequest The request.
     *
     * @throws LogicException When this is a decision call.
     */
    public function webContextRequest(): WebContextRequest
    {
        return $this->request instanceof WebContextRequest
            ? $this->request
            : throw new LogicException('This call is not a web-context call.');
    }

    /**
     * Local data attached to the call.
     *
     * Example:
     * ```php
     * $call->context()->get('ticket_id');
     * ```
     *
     * @return Context The context.
     */
    public function context(): Context
    {
        return $this->request->context;
    }

    /**
     * The model of the call: the requested one, or the connection default.
     *
     * Example:
     * ```php
     * $call->model(); // 'clef' when requested, otherwise e.g. 'jev-latest'
     * ```
     *
     * @return string|null The model, or null when neither is known.
     */
    public function model(): ?string
    {
        $requested = $this->request instanceof DecisionRequest ? $this->request->model : null;

        return $requested ?? $this->defaultModel;
    }

    /**
     * Milliseconds since the call started.
     *
     * Example:
     * ```php
     * $call->elapsedMs($clock->monotonicMs()); // 412
     * ```
     *
     * @param  float  $nowMs  The current monotonic time.
     * @return int Elapsed milliseconds, never negative.
     */
    public function elapsedMs(float $nowMs): int
    {
        return max(0, (int) round($nowMs - $this->startedAtMs));
    }
}
