<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Usage;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * Tokens consumed by a call, as reported in the response `usage` object.
 *
 * @api
 */
final readonly class Usage
{
    /**
     * Create the usage.
     *
     * Example:
     * ```php
     * new Usage(inputTokens: 120, outputTokens: 8);
     * ```
     *
     * @param  int  $inputTokens  `usage.input_tokens`.
     * @param  int  $outputTokens  `usage.output_tokens` (output tokens are free on Jev).
     * @param  float|null  $cost  `usage.cost`. Jev omits it, so null means "unknown", never
     *                            "free": read the real charge from {@see Billing}.
     *
     * @throws InvalidValue When a token count is negative, or the cost is negative, INF or NAN.
     */
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public ?float $cost = null,
    ) {
        Guard::nonNegative($inputTokens, 'usage input tokens');
        Guard::nonNegative($outputTokens, 'usage output tokens');

        if ($cost !== null) {
            Guard::nonNegativeFloat($cost, 'usage cost');
        }
    }

    /**
     * Usage of a call that consumed nothing, e.g. a fake or a rejected request.
     *
     * Example:
     * ```php
     * Usage::none()->totalTokens(); // 0
     * ```
     *
     * @return self Zero input and output tokens, unknown cost.
     */
    public static function none(): self
    {
        return new self(0, 0);
    }

    /**
     * Input plus output tokens.
     *
     * Example:
     * ```php
     * (new Usage(120, 8))->totalTokens(); // 128
     * ```
     *
     * @return int The total number of tokens.
     */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
