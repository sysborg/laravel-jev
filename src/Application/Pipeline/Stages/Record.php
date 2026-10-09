<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline\Stages;

use Closure;
use Psr\Log\LoggerInterface;
use Sysborg\LaravelJevai\Application\Pipeline\Call;
use Sysborg\LaravelJevai\Application\Pipeline\Middleware;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Usage\RunRecord;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\UsageRepository;
use Throwable;

/**
 * Stores one usage record per call (success or final failure), for token and cost accounting.
 *
 * A storage failure is logged and never breaks the call.
 */
final readonly class Record implements Middleware
{
    /**
     * Create the stage.
     *
     * Example:
     * ```php
     * new Record($repository, $clock, $logger, enabled: true, storePayloads: false);
     * ```
     *
     * @param  UsageRepository  $repository  Where records go.
     * @param  Clock  $clock  Timestamps the records.
     * @param  LoggerInterface  $logger  Receives storage failures.
     * @param  bool  $enabled  When false, nothing is stored.
     * @param  bool  $storePayloads  Whether the raw response body is stored with the record.
     */
    public function __construct(
        private UsageRepository $repository,
        private Clock $clock,
        private LoggerInterface $logger,
        private bool $enabled = true,
        private bool $storePayloads = false,
    ) {}

    /**
     * Record the outcome of the call.
     *
     * Example:
     * ```php
     * $stage->handle($call, $next); // one RunRecord stored
     * ```
     *
     * @param  Call  $call  The call.
     * @param  Closure(Call): (DecisionResult|WebContextResult)  $next  The rest of the pipeline.
     * @return DecisionResult|WebContextResult The result, unchanged.
     *
     * @throws JevException When the call fails (after the failure was recorded).
     */
    public function handle(Call $call, Closure $next): DecisionResult|WebContextResult
    {
        try {
            $result = $next($call);
        } catch (JevException $e) {
            $this->store($call, fn (): RunRecord => RunRecord::forFailure(
                $call->operation,
                $call->correlationId(),
                $e,
                $this->clock->now(),
                $call->elapsedMs($this->clock->monotonicMs()),
                max(1, $call->attempts),
                $call->context(),
                $call->model(),
                $call->connection,
                $call->request instanceof DecisionRequest ? $call->request->sessionId : null,
                $call->request instanceof DecisionRequest ? $call->request->user : null,
            ));

            throw $e;
        }

        $this->store($call, fn (): RunRecord => $result instanceof DecisionResult
            ? RunRecord::forDecision($call->decisionRequest(), $result, $this->clock->now(), $this->payload($result->raw))
            : RunRecord::forWebContext($call->webContextRequest(), $result, $this->clock->now(), $this->payload($result->raw)));

        return $result;
    }

    /**
     * Build and store a record, logging any failure.
     *
     * Example:
     * ```php
     * $this->store($call, fn () => RunRecord::forDecision(...));
     * ```
     *
     * @param  Call  $call  The call, for log context.
     * @param  Closure(): RunRecord  $record  Builds the record.
     * @return void Nothing.
     */
    private function store(Call $call, Closure $record): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            $this->repository->record($record());
        } catch (Throwable $e) {
            $this->logger->warning('Storing Jev usage failed; the Jev call was not affected.', [
                'correlation_id' => (string) $call->correlationId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The payload to store, when configured.
     *
     * Example:
     * ```php
     * $this->payload($result->raw); // null unless storePayloads is enabled
     * ```
     *
     * @param  array<string, mixed>|null  $raw  The raw response body.
     * @return array<string, mixed>|null The payload to store.
     */
    private function payload(?array $raw): ?array
    {
        return $this->storePayloads ? $raw : null;
    }
}
