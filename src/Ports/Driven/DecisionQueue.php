<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Ports\Driven;

use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\PendingDecision;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;

/**
 * Defers a decision to a background worker (e.g. a Laravel queue).
 *
 * The worker must evaluate the request with the given correlation id, so the
 * events it emits can be matched with the {@see PendingDecision}
 * returned to the caller. It must never retry a failed evaluation on its own:
 * Jev has no idempotency key, so a retried job could be billed twice.
 */
interface DecisionQueue
{
    /**
     * Queue one decision.
     *
     * Example:
     * ```php
     * $queue->push($request, $ids->correlationId());
     * ```
     *
     * @param  DecisionRequest  $request  The request to evaluate later.
     * @param  CorrelationId  $correlationId  Id the evaluation and its events must use.
     * @return void Nothing.
     */
    public function push(DecisionRequest $request, CorrelationId $correlationId): void;
}
