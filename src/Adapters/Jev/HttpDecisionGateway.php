<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;
use Sysborg\LaravelJevai\Domain\Exceptions\UnexpectedResponse;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\DecisionGateway;

/**
 * {@see DecisionGateway} over HTTP: `POST {base_url}/v1/systemone`.
 */
final readonly class HttpDecisionGateway implements DecisionGateway
{
    public const string PATH = 'v1/systemone';

    /**
     * Create the gateway.
     *
     * Example:
     * ```php
     * new HttpDecisionGateway(new JevHttpClient($http, $connection), $connection, new SystemClock);
     * ```
     *
     * @param  JevHttpClient  $client  Client bound to the connection.
     * @param  JevConnection  $connection  The connection, for its name and default model.
     * @param  Clock  $clock  Measures the attempt's latency.
     */
    public function __construct(
        private JevHttpClient $client,
        private JevConnection $connection,
        private Clock $clock,
    ) {}

    /**
     * Send one decision request.
     *
     * Example:
     * ```php
     * $gateway->decide($request, new CorrelationId('c-1'))->choice('department');
     * ```
     *
     * @param  DecisionRequest  $request  The request; a missing model falls back to the connection's.
     * @param  CorrelationId  $correlationId  Id to stamp on the result metadata.
     * @return DecisionResult Answers, usage, billing and metadata of this single attempt.
     *
     * @throws JevException When Jev answers with an error status (see {@see DecisionGateway::decide()}).
     * @throws TransportFailure When no response arrived.
     * @throws UnexpectedResponse When the response cannot be understood.
     */
    public function decide(DecisionRequest $request, CorrelationId $correlationId): DecisionResult
    {
        $started = $this->clock->monotonicMs();

        $response = $this->client->post(
            self::PATH,
            DecisionPayload::from($request, $this->connection->model),
            $request->judge,
        );

        return DecisionResultMapper::map(
            $response,
            $request,
            $correlationId,
            $this->connection,
            max(0, (int) round($this->clock->monotonicMs() - $started)),
        );
    }
}
