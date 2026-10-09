<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Usage;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * Aggregated usage of one group (or of the whole period).
 *
 * @api
 */
final readonly class UsageSummary
{
    /**
     * Create the summary.
     *
     * Example:
     * ```php
     * new UsageSummary('jev-latest', calls: 120, failures: 3, inputTokens: 48_000,
     *     outputTokens: 900, inputTokensCharged: 48_000, creditsCharged: 0.0, totalLatencyMs: 54_000);
     * ```
     *
     * @param  string|null  $group  Group key (model, day, ...), or null for an ungrouped total.
     * @param  int  $calls  Number of calls, failures included.
     * @param  int  $failures  Number of failed calls.
     * @param  int  $inputTokens  Sum of input tokens.
     * @param  int  $outputTokens  Sum of output tokens.
     * @param  int  $inputTokensCharged  Sum of paid input tokens charged.
     * @param  float  $creditsCharged  Sum of credits charged.
     * @param  int  $totalLatencyMs  Sum of call latencies in milliseconds.
     * @param  int  $uncertainBillings  Failed calls that may still have been billed.
     *
     * @throws InvalidValue When a total is negative or there are more failures than calls.
     */
    public function __construct(
        public ?string $group,
        public int $calls,
        public int $failures,
        public int $inputTokens,
        public int $outputTokens,
        public int $inputTokensCharged,
        public float $creditsCharged,
        public int $totalLatencyMs,
        public int $uncertainBillings = 0,
    ) {
        Guard::nonNegative($calls, 'usage summary calls');
        Guard::between($failures, 0, $calls, 'usage summary failures');
        Guard::nonNegative($inputTokens, 'usage summary input tokens');
        Guard::nonNegative($outputTokens, 'usage summary output tokens');
        Guard::nonNegative($inputTokensCharged, 'usage summary input tokens charged');
        Guard::nonNegativeFloat($creditsCharged, 'usage summary credits charged');
        Guard::nonNegative($totalLatencyMs, 'usage summary latency');
        Guard::between($uncertainBillings, 0, $failures, 'usage summary uncertain billings');
    }

    /**
     * Share of calls that failed.
     *
     * Example:
     * ```php
     * $summary->errorRate(); // 0.025
     * ```
     *
     * @return float From 0 to 1; 0 when there were no calls.
     */
    public function errorRate(): float
    {
        return $this->calls === 0 ? 0.0 : $this->failures / $this->calls;
    }

    /**
     * Mean latency per call.
     *
     * Example:
     * ```php
     * $summary->averageLatencyMs(); // 450.0
     * ```
     *
     * @return float Milliseconds; 0 when there were no calls.
     */
    public function averageLatencyMs(): float
    {
        return $this->calls === 0 ? 0.0 : $this->totalLatencyMs / $this->calls;
    }
}
