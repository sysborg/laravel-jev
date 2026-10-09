<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use Sysborg\LaravelJevai\Domain\Account\Balance;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;
use Sysborg\LaravelJevai\Domain\Exceptions\UnexpectedResponse;
use Sysborg\LaravelJevai\Ports\Driven\AccountGateway;

/**
 * {@see AccountGateway} over HTTP: `GET /v1/models` and `GET /v1/credits`.
 */
final readonly class HttpAccountGateway implements AccountGateway
{
    public const string MODELS_PATH = 'v1/models';

    public const string CREDITS_PATH = 'v1/credits';

    /**
     * Create the gateway.
     *
     * Example:
     * ```php
     * new HttpAccountGateway(new JevHttpClient($http, $connection));
     * ```
     *
     * @param  JevHttpClient  $client  Client bound to the connection.
     */
    public function __construct(
        private JevHttpClient $client,
    ) {}

    /**
     * List the model names available to the account.
     *
     * The body shape is not documented, so a list of names, a list of objects with
     * `id` or `name`, and either of those wrapped in `data` or `models` are accepted.
     *
     * Example:
     * ```php
     * $gateway->models(); // ['jev-latest', 'clef', ...]
     * ```
     *
     * @return list<string> The model names, without duplicates.
     *
     * @throws JevException When Jev answers with an error status.
     * @throws TransportFailure When no response arrived.
     * @throws UnexpectedResponse When no list of models can be found in the body.
     */
    public function models(): array
    {
        $response = $this->client->get(self::MODELS_PATH);
        $body = json_decode($response->body(), true);

        $items = match (true) {
            is_array($body) && array_is_list($body) => $body,
            is_array($body) && is_array($body['data'] ?? null) => $body['data'],
            is_array($body) && is_array($body['models'] ?? null) => $body['models'],
            default => throw new UnexpectedResponse('Jev returned no list of models.', $response->status()),
        };

        $names = [];

        foreach ($items as $item) {
            $name = match (true) {
                is_string($item) => $item,
                is_array($item) && is_string($item['id'] ?? null) => $item['id'],
                is_array($item) && is_string($item['name'] ?? null) => $item['name'],
                default => null,
            };

            if ($name !== null && trim($name) !== '') {
                $names[$name] = true;
            }
        }

        return array_map(strval(...), array_keys($names));
    }

    /**
     * Read the remaining credits and paid input tokens.
     *
     * Example:
     * ```php
     * $gateway->balance()->creditsRemaining; // 42.0
     * ```
     *
     * @return Balance What is left on the account.
     *
     * @throws JevException When Jev answers with an error status.
     * @throws TransportFailure When no response arrived.
     * @throws UnexpectedResponse When `creditsRemaining` or `paidInputTokensRemaining` is missing or invalid.
     */
    public function balance(): Balance
    {
        $response = $this->client->get(self::CREDITS_PATH);
        $body = ResponseReader::fromResponse($response);

        try {
            return new Balance(
                $body->float('creditsRemaining'),
                $body->optionalInt('paidInputTokensRemaining') ?? throw $body->invalid('paidInputTokensRemaining', 'an integer'),
            );
        } catch (InvalidValue $e) {
            throw new UnexpectedResponse('Jev returned a negative balance.', $response->status(), $e);
        }
    }
}
