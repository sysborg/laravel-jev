<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\UseCases;

use LogicException;
use Sysborg\LaravelJevai\Application\Pipeline\Call;
use Sysborg\LaravelJevai\Application\Pipeline\Pipeline;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Ports\Driven\WebContextGateway;

/**
 * Resolves a web-context question through the full pipeline.
 */
final readonly class ResolveWebContext
{
    /**
     * Create the use case.
     *
     * Example:
     * ```php
     * new ResolveWebContext($gateway, $pipeline);
     * ```
     *
     * @param  WebContextGateway  $gateway  Sends single attempts.
     * @param  Pipeline  $pipeline  Correlation, validation, redaction, events, usage, metrics, tracing, retries.
     */
    public function __construct(
        private WebContextGateway $gateway,
        private Pipeline $pipeline,
    ) {}

    /**
     * Resolve the request.
     *
     * Example:
     * ```php
     * $result = $resolve->execute(WebContextRequest::ask('Has GPT-6 been released?'));
     * ```
     *
     * @param  WebContextRequest  $request  The request.
     * @param  CorrelationId|null  $correlationId  A preset id, or null to generate one.
     * @return WebContextResult The result, with total latency and attempts.
     *
     * @throws InvalidValue When the request breaks a Jev limit (nothing is sent).
     * @throws JevException When the call fails after the configured retries.
     */
    public function execute(WebContextRequest $request, ?CorrelationId $correlationId = null): WebContextResult
    {
        $result = $this->pipeline->run(
            Call::forWebContext($request, $correlationId),
            fn (Call $call): WebContextResult => $this->gateway->resolve($call->webContextRequest(), $call->correlationId()),
        );

        return $result instanceof WebContextResult
            ? $result
            : throw new LogicException('A pipeline stage replaced the web-context result.');
    }
}
