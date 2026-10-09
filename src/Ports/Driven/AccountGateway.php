<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Ports\Driven;

use Sysborg\LaravelJevai\Domain\Account\Balance;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;

/**
 * Reads account information from Jev. These calls run no inference and cost nothing.
 */
interface AccountGateway
{
    /**
     * List the model names available to the account (`GET /v1/models`).
     *
     * Example:
     * ```php
     * $gateway->models(); // ['jev-latest', 'jev-1.13.0', 'laya-english', 'clef', ...]
     * ```
     *
     * @return list<string> The model names.
     *
     * @throws JevException When the call fails (see the subclasses for each status).
     */
    public function models(): array;

    /**
     * Read the remaining credits and paid input tokens (`GET /v1/credits`).
     *
     * Example:
     * ```php
     * $gateway->balance()->paidInputTokensRemaining; // 1_250_000
     * ```
     *
     * @return Balance What is left on the account.
     *
     * @throws JevException When the call fails (see the subclasses for each status).
     */
    public function balance(): Balance;
}
