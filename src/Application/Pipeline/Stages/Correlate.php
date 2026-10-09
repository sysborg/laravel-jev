<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline\Stages;

use Closure;
use Sysborg\LaravelJevai\Application\Pipeline\Call;
use Sysborg\LaravelJevai\Application\Pipeline\Middleware;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\IdGenerator;

/**
 * Gives the call its correlation id and start time, and links Jev's records to it.
 *
 * For decisions, the id is added to the private `trace` (so Jev's usage page can be
 * matched with Laravel logs) and, when enabled, used as `session_id` if none was set.
 */
final readonly class Correlate implements Middleware
{
    /**
     * Create the stage.
     *
     * Example:
     * ```php
     * new Correlate($ids, $clock, traceKey: 'correlation_id', sessionFallback: true,
     *     connection: 'default', defaultModel: 'jev-latest');
     * ```
     *
     * @param  IdGenerator  $ids  Creates correlation ids.
     * @param  Clock  $clock  Marks the start of the call.
     * @param  string|null  $traceKey  Trace field receiving the id; null disables the injection.
     * @param  bool  $sessionFallback  Whether the id becomes `session_id` when none was set.
     * @param  string|null  $connection  Package connection the gateways use, for events and records.
     * @param  string|null  $defaultModel  The connection's default model, for events and records.
     */
    public function __construct(
        private IdGenerator $ids,
        private Clock $clock,
        private ?string $traceKey = 'correlation_id',
        private bool $sessionFallback = true,
        private ?string $connection = null,
        private ?string $defaultModel = null,
    ) {}

    /**
     * Correlate the call, then continue.
     *
     * Example:
     * ```php
     * $stage->handle($call, $next); // $call->correlationId is now set
     * ```
     *
     * @param  Call  $call  The call; a preset correlation id (queued calls) is kept.
     * @param  Closure(Call): (DecisionResult|WebContextResult)  $next  The rest of the pipeline.
     * @return DecisionResult|WebContextResult The result.
     *
     * @throws InvalidValue When the trace has no room left for the correlation id.
     * @throws JevException When the call fails.
     */
    public function handle(Call $call, Closure $next): DecisionResult|WebContextResult
    {
        $call->correlationId ??= $this->ids->correlationId();
        $call->startedAtMs = $this->clock->monotonicMs();
        $call->connection ??= $this->connection;
        $call->defaultModel ??= $this->defaultModel;

        if ($call->request instanceof DecisionRequest) {
            $call->request = $this->link($call->request, (string) $call->correlationId);
        }

        return $next($call);
    }

    /**
     * Add the id to the trace and, when enabled, as the session label.
     *
     * Example:
     * ```php
     * $this->link($request, 'c-1'); // trace.correlation_id = 'c-1', session_id = 'c-1'
     * ```
     *
     * @param  DecisionRequest  $request  The request.
     * @param  string  $correlationId  The id.
     * @return DecisionRequest The linked request.
     *
     * @throws InvalidValue When the trace has no room left for the id.
     */
    private function link(DecisionRequest $request, string $correlationId): DecisionRequest
    {
        if ($this->traceKey !== null && ! $request->trace->has($this->traceKey)) {
            $request = $request->withTrace($request->trace->with($this->traceKey, $correlationId));
        }

        if ($this->sessionFallback && $request->sessionId === null) {
            $request = $request->withSessionId($correlationId);
        }

        return $request;
    }
}
