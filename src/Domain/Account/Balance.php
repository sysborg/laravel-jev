<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Account;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * What is left on the Jev account (`GET /v1/credits`).
 *
 * @api
 */
final readonly class Balance
{
    /**
     * Create the balance.
     *
     * Example:
     * ```php
     * new Balance(creditsRemaining: 42.0, paidInputTokensRemaining: 1_250_000);
     * ```
     *
     * @param  float  $creditsRemaining  `creditsRemaining`.
     * @param  int  $paidInputTokensRemaining  `paidInputTokensRemaining`.
     *
     * @throws InvalidValue When a value is negative, INF or NAN.
     */
    public function __construct(
        public float $creditsRemaining,
        public int $paidInputTokensRemaining,
    ) {
        Guard::nonNegativeFloat($creditsRemaining, 'credits remaining');
        Guard::nonNegative($paidInputTokensRemaining, 'paid input tokens remaining');
    }

    /**
     * Whether neither balance can pay for another call.
     *
     * Example:
     * ```php
     * if ($balance->isExhausted()) {
     *     Notification::route('mail', 'ops@example.com')->notify(new JevBalanceExhausted);
     * }
     * ```
     *
     * @return bool True when both credits and paid input tokens are zero.
     */
    public function isExhausted(): bool
    {
        return $this->creditsRemaining <= 0 && $this->paidInputTokensRemaining <= 0;
    }
}
