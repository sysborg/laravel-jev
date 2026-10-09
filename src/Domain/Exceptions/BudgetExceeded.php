<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

/**
 * The daily budget of charged input tokens is used up: the call was not sent. Not billed.
 *
 * @api
 */
final class BudgetExceeded extends JevException
{
    /**
     * Create the exception.
     *
     * Example:
     * ```php
     * throw new BudgetExceeded(usedTokens: 1_000_420, limitTokens: 1_000_000);
     * ```
     *
     * @param  int  $usedTokens  Input tokens charged today (UTC).
     * @param  int  $limitTokens  The configured daily limit.
     */
    public function __construct(
        public readonly int $usedTokens,
        public readonly int $limitTokens,
    ) {
        parent::__construct("Jev daily budget of {$limitTokens} charged input tokens reached ({$usedTokens} used today, UTC).");
    }
}
