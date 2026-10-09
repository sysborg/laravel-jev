<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use Illuminate\Http\Client\Factory as HttpFactory;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Ports\Driven\Clock;

/**
 * Builds the HTTP gateways of a named connection.
 */
final readonly class GatewayFactory
{
    /**
     * Create the factory.
     *
     * Example:
     * ```php
     * new GatewayFactory(app(HttpFactory::class), app(ConnectionRegistry::class), new SystemClock);
     * ```
     *
     * @param  HttpFactory  $http  Laravel's HTTP client factory.
     * @param  ConnectionRegistry  $connections  Configured connections.
     * @param  Clock  $clock  Measures attempt latency.
     */
    public function __construct(
        private HttpFactory $http,
        private ConnectionRegistry $connections,
        private Clock $clock,
    ) {}

    /**
     * The decision gateway of a connection.
     *
     * Example:
     * ```php
     * $factory->decision('tenant-a')->decide($request, $correlationId);
     * ```
     *
     * @param  string|null  $connection  Connection name, or null for the default.
     * @return HttpDecisionGateway The gateway.
     *
     * @throws InvalidValue When the connection is not configured or invalid.
     */
    public function decision(?string $connection = null): HttpDecisionGateway
    {
        $resolved = $this->connections->get($connection);

        return new HttpDecisionGateway(new JevHttpClient($this->http, $resolved), $resolved, $this->clock);
    }

    /**
     * The web-context gateway of a connection.
     *
     * Example:
     * ```php
     * $factory->webContext()->resolve($request, $correlationId);
     * ```
     *
     * @param  string|null  $connection  Connection name, or null for the default.
     * @return HttpWebContextGateway The gateway.
     *
     * @throws InvalidValue When the connection is not configured or invalid.
     */
    public function webContext(?string $connection = null): HttpWebContextGateway
    {
        $resolved = $this->connections->get($connection);

        return new HttpWebContextGateway(new JevHttpClient($this->http, $resolved), $resolved, $this->clock);
    }

    /**
     * The account gateway of a connection.
     *
     * Example:
     * ```php
     * $factory->account()->balance();
     * ```
     *
     * @param  string|null  $connection  Connection name, or null for the default.
     * @return HttpAccountGateway The gateway.
     *
     * @throws InvalidValue When the connection is not configured or invalid.
     */
    public function account(?string $connection = null): HttpAccountGateway
    {
        return new HttpAccountGateway(new JevHttpClient($this->http, $this->connections->get($connection)));
    }
}
