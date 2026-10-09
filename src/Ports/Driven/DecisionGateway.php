<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Ports\Driven;

use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\ApiError;
use Sysborg\LaravelJevai\Domain\Exceptions\InsufficientCredits;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidRequest;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeNotFound;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeRevisionMismatch;
use Sysborg\LaravelJevai\Domain\Exceptions\RateLimited;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;
use Sysborg\LaravelJevai\Domain\Exceptions\Unauthorized;
use Sysborg\LaravelJevai\Domain\Exceptions\UnexpectedResponse;
use Sysborg\LaravelJevai\Domain\Exceptions\UpstreamFailure;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;

/**
 * Sends decision requests to Jev (`POST /v1/systemone`).
 *
 * Implementations perform exactly one attempt: no retries, no events, no
 * logging of request data. Retries, observability and usage recording are
 * the application layer's job, so every adapter (HTTP, fake) behaves the same.
 */
interface DecisionGateway
{
    /**
     * Send one decision request and map the response into the domain.
     *
     * The request arrives with its model resolved and the correlation id already
     * injected into its trace. The returned metadata reports a single attempt.
     *
     * Example:
     * ```php
     * $result = $gateway->decide($request, $correlationId);
     * $result->choice('department')->choice; // 'billing'
     * ```
     *
     * @param  DecisionRequest  $request  The request to send.
     * @param  CorrelationId  $correlationId  Id to stamp on the result metadata.
     * @return DecisionResult Answers, usage, billing and metadata of the attempt.
     *
     * @throws Unauthorized On 401.
     * @throws InsufficientCredits On 402.
     * @throws JudgeNotFound On 404 for a judge call.
     * @throws JudgeRevisionMismatch On 409 for a pinned judge call.
     * @throws InvalidRequest On 400 or 422.
     * @throws RateLimited On 429, with `Retry-After` when sent.
     * @throws UpstreamFailure On 502, 503 or 504.
     * @throws TransportFailure When no response arrived (timeout, connection error).
     * @throws UnexpectedResponse When the response body cannot be understood.
     * @throws ApiError On any other error status.
     */
    public function decide(DecisionRequest $request, CorrelationId $correlationId): DecisionResult;
}
