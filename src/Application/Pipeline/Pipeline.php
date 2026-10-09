<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline;

use Closure;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;

/**
 * Runs a call through the stages, outermost first, then the gateway.
 */
final readonly class Pipeline
{
    /** @var list<Middleware> */
    private array $stages;

    /**
     * Create the pipeline.
     *
     * Example:
     * ```php
     * new Pipeline($correlate, $validate, $redact, $emit, $record, $measure, $trace, $retry);
     * ```
     *
     * @param  Middleware  ...$stages  Stages, outermost first.
     */
    public function __construct(Middleware ...$stages)
    {
        $this->stages = array_values($stages);
    }

    /**
     * Run a call.
     *
     * Example:
     * ```php
     * $result = $pipeline->run(Call::forDecision($request),
     *     fn (Call $call) => $gateway->decide($call->decisionRequest(), $call->correlationId()));
     * ```
     *
     * @param  Call  $call  The call.
     * @param  Closure(Call): (DecisionResult|WebContextResult)  $terminal  Sends one attempt (the gateway).
     * @return DecisionResult|WebContextResult The result.
     *
     * @throws JevException When the call fails.
     */
    public function run(Call $call, Closure $terminal): DecisionResult|WebContextResult
    {
        $next = $terminal;

        foreach (array_reverse($this->stages) as $stage) {
            $next = static fn (Call $call): DecisionResult|WebContextResult => $stage->handle($call, $next);
        }

        return $next($call);
    }

    /**
     * The stages, outermost first.
     *
     * Example:
     * ```php
     * array_map(fn ($s) => $s::class, $pipeline->stages()); // [Correlate::class, ...]
     * ```
     *
     * @return list<Middleware> The stages.
     */
    public function stages(): array
    {
        return $this->stages;
    }
}
