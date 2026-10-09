<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Sysborg\LaravelJevai\Domain\Decision\JudgeRef;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;

/**
 * Sends authenticated JSON requests to one Jev connection.
 *
 * Exactly one attempt per call: Laravel's HTTP client does not retry unless
 * asked to, and this class never asks.
 */
final readonly class JevHttpClient
{
    public const string USER_AGENT = 'sysborg/laravel-jevai';

    /**
     * Create the client.
     *
     * Example:
     * ```php
     * new JevHttpClient(app(HttpFactory::class), $registry->get('default'));
     * ```
     *
     * @param  HttpFactory  $http  Laravel's HTTP client factory (fakeable with `Http::fake()`).
     * @param  JevConnection  $connection  Where and how to connect.
     */
    public function __construct(
        private HttpFactory $http,
        private JevConnection $connection,
    ) {}

    /**
     * POST a JSON payload and return the successful response.
     *
     * Example:
     * ```php
     * $response = $client->post('v1/systemone', $payload, $request->judge);
     * ```
     *
     * @param  string  $path  Path relative to the base URL, e.g. `v1/systemone`.
     * @param  array<string, mixed>  $payload  JSON body.
     * @param  JudgeRef|null  $judge  Judge of the request, to name it in 404/409 errors.
     * @return Response A 2xx response.
     *
     * @throws JevException When the status is not 2xx (mapped by {@see ErrorMapper}).
     * @throws TransportFailure When no response arrived.
     */
    public function post(string $path, array $payload, ?JudgeRef $judge = null): Response
    {
        return $this->send(fn (PendingRequest $request): Response => $request->post($path, $payload), $judge);
    }

    /**
     * GET a resource and return the successful response.
     *
     * Example:
     * ```php
     * $response = $client->get('v1/credits');
     * ```
     *
     * @param  string  $path  Path relative to the base URL, e.g. `v1/models`.
     * @return Response A 2xx response.
     *
     * @throws JevException When the status is not 2xx (mapped by {@see ErrorMapper}).
     * @throws TransportFailure When no response arrived.
     */
    public function get(string $path): Response
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get($path), null);
    }

    /**
     * Run one request, turning transport errors and error statuses into domain exceptions.
     *
     * Example:
     * ```php
     * $this->send(fn (PendingRequest $request) => $request->get('v1/models'), null);
     * ```
     *
     * @param  callable(PendingRequest): Response  $call  Performs the request.
     * @param  JudgeRef|null  $judge  Judge of the request, for error mapping.
     * @return Response A 2xx response.
     *
     * @throws JevException When the status is not 2xx.
     * @throws TransportFailure When no response arrived.
     */
    private function send(callable $call, ?JudgeRef $judge): Response
    {
        try {
            $response = $call($this->request());
        } catch (ConnectionException|TransferException $e) {
            throw new TransportFailure(
                "Could not get a response from Jev connection [{$this->connection->name}] (".class_basename($e).').',
                $e,
            );
        }

        if (! $response->successful()) {
            throw ErrorMapper::fromResponse($response, $judge);
        }

        return $response;
    }

    /**
     * A pending request with the connection's base URL, key, timeouts and JSON headers.
     *
     * Example:
     * ```php
     * $this->request()->get('v1/models');
     * ```
     *
     * @return PendingRequest The configured request.
     */
    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->connection->baseUrl)
            ->withToken($this->connection->apiKey->reveal())
            ->withUserAgent(self::USER_AGENT)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->connection->connectTimeout)
            ->timeout($this->connection->requestTimeout);
    }
}
