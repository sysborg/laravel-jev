<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Laravel;

use Illuminate\Contracts\Bus\Dispatcher;
use Sysborg\LaravelJevai\Adapters\Queue\EvaluateDecisionJob;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Ports\Driven\DecisionQueue;

/**
 * {@see DecisionQueue} on Laravel queues, dispatching {@see EvaluateDecisionJob}.
 */
final readonly class LaravelDecisionQueue implements DecisionQueue
{
    /**
     * Create the queue.
     *
     * Example:
     * ```php
     * new LaravelDecisionQueue(app(Dispatcher::class), connection: 'redis', queue: 'jev');
     * ```
     *
     * @param  Dispatcher  $bus  Laravel's bus dispatcher.
     * @param  string|null  $connection  Queue connection, or null for the default.
     * @param  string|null  $queue  Queue name, or null for the default.
     */
    public function __construct(
        private Dispatcher $bus,
        private ?string $connection = null,
        private ?string $queue = null,
    ) {}

    /**
     * Dispatch the job.
     *
     * Example:
     * ```php
     * $queue->push($request, $correlationId);
     * ```
     *
     * @param  DecisionRequest  $request  The request to evaluate later.
     * @param  CorrelationId  $correlationId  Id the evaluation and its events use.
     * @return void Nothing.
     */
    public function push(DecisionRequest $request, CorrelationId $correlationId): void
    {
        $job = new EvaluateDecisionJob($request, $correlationId);

        if ($this->connection !== null) {
            $job->onConnection($this->connection);
        }

        if ($this->queue !== null) {
            $job->onQueue($this->queue);
        }

        $this->bus->dispatch($job);
    }
}
