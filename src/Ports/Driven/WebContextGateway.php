<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Ports\Driven;

use Sysborg\LaravelJevai\Domain\Exceptions\ApiError;
use Sysborg\LaravelJevai\Domain\Exceptions\InsufficientCredits;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidRequest;
use Sysborg\LaravelJevai\Domain\Exceptions\RateLimited;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;
use Sysborg\LaravelJevai\Domain\Exceptions\Unauthorized;
use Sysborg\LaravelJevai\Domain\Exceptions\UnexpectedResponse;
use Sysborg\LaravelJevai\Domain\Exceptions\UpstreamFailure;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;

/**
 * Sends web-context requests to Jev (`POST /v1/web-context`).
 *
 * Same contract as {@see DecisionGateway}: one attempt, no side effects.
 */
interface WebContextGateway
{
    /**
     * Send one web-context request and map the response into the domain.
     *
     * Example:
     * ```php
     * $result = $gateway->resolve(WebContextRequest::ask('Has GPT-6 been released?'), $correlationId);
     * $result->isYes(); // true
     * ```
     *
     * @param  WebContextRequest  $request  The request to send.
     * @param  CorrelationId  $correlationId  Id to stamp on the result metadata.
     * @return WebContextResult Decision, evidence, usage, latency, billing and metadata of the attempt.
     *
     * @throws Unauthorized On 401.
     * @throws InsufficientCredits On 402.
     * @throws InvalidRequest On 400 or 422.
     * @throws RateLimited On 429, with `Retry-After` when sent.
     * @throws UpstreamFailure On 502, 503 or 504.
     * @throws TransportFailure When no response arrived (timeout, connection error).
     * @throws UnexpectedResponse When the response body cannot be understood.
     * @throws ApiError On any other error status.
     */
    public function resolve(WebContextRequest $request, CorrelationId $correlationId): WebContextResult;
}
