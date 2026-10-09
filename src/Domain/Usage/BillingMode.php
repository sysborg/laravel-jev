<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Usage;

/**
 * How Jev paid for a call (`X-Jev-Billing` / `billing.mode`).
 */
enum BillingMode: string
{
    /** Paid input tokens covered the call. */
    case Tokens = 'tokens';

    /** Credits covered the call. */
    case Credits = 'credits';

    /** Not enough tokens: the whole call was charged in credits. */
    case CreditsFallback = 'credits-fallback';

    /** Not enough credits: the call was charged in tokens. */
    case TokensFallback = 'tokens-fallback';

    /** No billing information, or a mode this package does not know yet. */
    case Unknown = 'unknown';

    /**
     * Parse a header or body value, never failing on new or missing values.
     *
     * Example:
     * ```php
     * BillingMode::fromValue($response->header('X-Jev-Billing')); // BillingMode::Tokens
     * BillingMode::fromValue('something-new');                    // BillingMode::Unknown
     * ```
     *
     * @param  string|null  $value  Raw value; case and surrounding spaces are ignored.
     * @return self The matching mode, or {@see self::Unknown}.
     */
    public static function fromValue(?string $value): self
    {
        return $value === null ? self::Unknown : (self::tryFrom(strtolower(trim($value))) ?? self::Unknown);
    }

    /**
     * Whether the preferred balance could not cover the call.
     *
     * Example:
     * ```php
     * if ($billing->mode->isFallback()) {
     *     Log::notice('Jev fell back to the secondary balance.');
     * }
     * ```
     *
     * @return bool True for both fallback modes.
     */
    public function isFallback(): bool
    {
        return $this === self::CreditsFallback || $this === self::TokensFallback;
    }

    /**
     * Whether the call was paid with credits.
     *
     * Example:
     * ```php
     * BillingMode::CreditsFallback->chargesCredits(); // true
     * ```
     *
     * @return bool True for credits and credits-fallback.
     */
    public function chargesCredits(): bool
    {
        return $this === self::Credits || $this === self::CreditsFallback;
    }
}
