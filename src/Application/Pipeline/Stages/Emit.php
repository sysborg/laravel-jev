<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline\Stages;

use Closure;
use Sysborg\LaravelJevai\Application\Pipeline\Call;
use Sysborg\LaravelJevai\Application\Pipeline\Middleware;
use Sysborg\LaravelJevai\Application\Support\SafeEventPublisher;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Events\DecisionFailed;
use Sysborg\LaravelJevai\Domain\Events\DecisionRequested;
use Sysborg\LaravelJevai\Domain\Events\DecisionSucceeded;
use Sysborg\LaravelJevai\Domain\Events\TokenUsageRecorded;
use Sysborg\LaravelJevai\Domain\Events\WebContextResolved;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Ports\Driven\Clock;

/**
 * Publishes the lifecycle of the call: requested, then succeeded (with token usage) or failed.
 *
 * Runs outside the Retry stage, so each call emits exactly one outcome event.
 */
final readonly class Emit implements Middleware
{
    /**
     * Create the stage.
     *
     * Example:
     * ```php
     * new Emit($events, $clock, includeRaw: false);
     * ```
     *
     * @param  SafeEventPublisher  $events  Publishes without letting listeners break the call.
     * @param  Clock  $clock  Timestamps the events.
     * @param  bool  $includeRaw  Whether success events keep the raw response body.
     */
    public function __construct(
        private SafeEventPublisher $events,
        private Clock $clock,
        private bool $includeRaw = false,
    ) {}

    /**
     * Emit around the call.
     *
     * Example:
     * ```php
     * $stage->handle($call, $next); // DecisionRequested, then DecisionSucceeded + TokenUsageRecorded
     * ```
     *
     * @param  Call  $call  The call.
     * @param  Closure(Call): (DecisionResult|WebContextResult)  $next  The rest of the pipeline.
     * @return DecisionResult|WebContextResult The result, unchanged (raw included).
     *
     * @throws JevException When the call fails (after `DecisionFailed` was published).
     */
    public function handle(Call $call, Closure $next): DecisionResult|WebContextResult
    {
        $this->events->publish($this->requested($call));

        try {
            $result = $next($call);
        } catch (JevException $e) {
            $this->events->publish(DecisionFailed::fromException(
                $call->operation,
                $call->correlationId(),
                $call->context(),
                $this->clock->now(),
                $e,
                max(1, $call->attempts),
                $call->elapsedMs($this->clock->monotonicMs()),
                $call->model(),
                $call->connection,
            ));

            throw $e;
        }

        $now = $this->clock->now();

        $this->events->publish($result instanceof DecisionResult
            ? new DecisionSucceeded($call->operation, $this->includeRaw ? $result : $result->withoutRaw(), $call->context(), $now)
            : new WebContextResolved($this->includeRaw ? $result : $result->withoutRaw(), $call->context(), $now));

        $this->events->publish(new TokenUsageRecorded(
            $call->operation,
            $result->meta->correlationId,
            $call->context(),
            $now,
            $result->meta->model,
            $result->meta->connection,
            $result instanceof DecisionResult ? $result->usage : $result->usage->toUsage(),
            $result->billing,
        ));

        return $result;
    }

    /**
     * Describe the call that is about to start.
     *
     * Example:
     * ```php
     * $this->requested($call); // DecisionRequested
     * ```
     *
     * @param  Call  $call  The call.
     * @return DecisionRequested The event.
     */
    private function requested(Call $call): DecisionRequested
    {
        $request = $call->request;

        return new DecisionRequested(
            operation: $call->operation,
            correlationId: $call->correlationId(),
            context: $call->context(),
            occurredAt: $this->clock->now(),
            model: $call->model(),
            questionIds: $request instanceof DecisionRequest ? ($request->questions?->ids() ?? []) : [],
            judgeId: $request instanceof DecisionRequest ? $request->judge?->id : null,
            judgeRevision: $request instanceof DecisionRequest ? $request->judge?->revision : null,
            sessionId: $request instanceof DecisionRequest ? $request->sessionId : null,
            user: $request instanceof DecisionRequest ? $request->user : null,
            statePreview: $call->statePreview,
            connection: $call->connection,
        );
    }
}
