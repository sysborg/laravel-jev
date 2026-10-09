<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;
use Sysborg\LaravelJevai\Domain\Exceptions\UnexpectedResponse;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\WebContextGateway;

/**
 * {@see WebContextGateway} over HTTP: `POST {base_url}/v1/web-context`.
 */
final readonly class HttpWebContextGateway implements WebContextGateway
{
    public const string PATH = 'v1/web-context';

    /**
     * Create the gateway.
     *
     * Example:
     * ```php
     * new HttpWebContextGateway(new JevHttpClient($http, $connection), $connection, new SystemClock);
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
     * Send one web-context request.
     *
     * Example:
     * ```php
     * $gateway->resolve(WebContextRequest::ask('Has GPT-6 been released?'), new CorrelationId('c-1'))->isYes();
     * ```
     *
     * @param  WebContextRequest  $request  The request to send.
     * @param  CorrelationId  $correlationId  Id to stamp on the result metadata.
     * @return WebContextResult Decision, evidence, usage, latency, billing and metadata of this attempt.
     *
     * @throws JevException When Jev answers with an error status (see {@see WebContextGateway::resolve()}).
     * @throws TransportFailure When no response arrived.
     * @throws UnexpectedResponse When the response cannot be understood.
     */
    public function resolve(WebContextRequest $request, CorrelationId $correlationId): WebContextResult
    {
        $started = $this->clock->monotonicMs();

        $response = $this->client->post(self::PATH, WebContextPayload::from($request));

        return WebContextResultMapper::map(
            $response,
            $correlationId,
            $this->connection,
            max(0, (int) round($this->clock->monotonicMs() - $started)),
        );
    }
}
