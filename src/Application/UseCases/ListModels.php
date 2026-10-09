<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\UseCases;

use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Ports\Driven\AccountGateway;

/**
 * Lists the models available to the account. Free: runs no inference.
 */
final readonly class ListModels
{
    /**
     * Create the use case.
     *
     * Example:
     * ```php
     * new ListModels($accountGateway);
     * ```
     *
     * @param  AccountGateway  $gateway  Reads account information.
     */
    public function __construct(
        private AccountGateway $gateway,
    ) {}

    /**
     * List the models.
     *
     * Example:
     * ```php
     * $listModels->execute(); // ['jev-latest', 'clef', ...]
     * ```
     *
     * @return list<string> The model names.
     *
     * @throws JevException When the call fails.
     */
    public function execute(): array
    {
        return $this->gateway->models();
    }
}
