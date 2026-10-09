<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Usage;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * What Jev charged for a call and what is left on the account.
 *
 * Output tokens are free; each call draws on either paid input tokens
 * (multiplied by the model rate) or credits, never both.
 */
final readonly class Billing
{
    /**
     * Create the billing information.
     *
     * Example:
     * ```php
     * new Billing(BillingMode::Tokens, inputTokensCharged: 120, tokensRemaining: 9_880);
     * new Billing(BillingMode::CreditsFallback, creditsCharged: 1.0);
     * ```
     *
     * @param  BillingMode  $mode  How the call was paid.
     * @param  int  $inputTokensCharged  Paid input tokens charged, after the model rate multiplier.
     * @param  float  $creditsCharged  Credits charged.
     * @param  int|null  $tokensRemaining  Paid input tokens left after the call, when reported.
     *
     * @throws InvalidValue When a charge or the remaining balance is negative, INF or NAN.
     */
    public function __construct(
        public BillingMode $mode,
        public int $inputTokensCharged = 0,
        public float $creditsCharged = 0.0,
        public ?int $tokensRemaining = null,
    ) {
        Guard::nonNegative($inputTokensCharged, 'billing input tokens charged');
        Guard::nonNegativeFloat($creditsCharged, 'billing credits charged');

        if ($tokensRemaining !== null) {
            Guard::nonNegative($tokensRemaining, 'billing tokens remaining');
        }
    }

    /**
     * Billing of a call without billing information.
     *
     * Example:
     * ```php
     * Billing::unknown()->mode; // BillingMode::Unknown
     * ```
     *
     * @return self Unknown mode, nothing charged, unknown balance.
     */
    public static function unknown(): self
    {
        return new self(BillingMode::Unknown);
    }

    /**
     * Whether anything was charged.
     *
     * Example:
     * ```php
     * if ($result->billing->wasCharged()) {
     *     $this->recordSpend($result->billing);
     * }
     * ```
     *
     * @return bool True when tokens or credits were charged.
     */
    public function wasCharged(): bool
    {
        return $this->inputTokensCharged > 0 || $this->creditsCharged > 0;
    }
}
