<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\UseCases;

use Sysborg\LaravelJevai\Domain\Account\Balance;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Ports\Driven\AccountGateway;

/**
 * Reads the remaining credits and paid input tokens. Free: runs no inference.
 */
final readonly class GetBalance
{
    /**
     * Create the use case.
     *
     * Example:
     * ```php
     * new GetBalance($accountGateway);
     * ```
     *
     * @param  AccountGateway  $gateway  Reads account information.
     */
    public function __construct(
        private AccountGateway $gateway,
    ) {}

    /**
     * Read the balance.
     *
     * Example:
     * ```php
     * $getBalance->execute()->creditsRemaining; // 42.0
     * ```
     *
     * @return Balance What is left on the account.
     *
     * @throws JevException When the call fails.
     */
    public function execute(): Balance
    {
        return $this->gateway->balance();
    }
}
