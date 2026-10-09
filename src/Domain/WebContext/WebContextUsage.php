<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\WebContext;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;
use Sysborg\LaravelJevai\Domain\Usage\Usage;

/**
 * Tokens and calls consumed by a web-context request.
 */
final readonly class WebContextUsage
{
    /**
     * Create the usage.
     *
     * Example:
     * ```php
     * new WebContextUsage(inputTokens: 2140, outputTokens: 12, jevCalls: 2, webSearch: true);
     * ```
     *
     * @param  int  $inputTokens  `usage.input_tokens`.
     * @param  int  $outputTokens  `usage.output_tokens`.
     * @param  int  $jevCalls  Number of Jev evaluations performed (with and without evidence).
     * @param  bool  $webSearch  Whether a web search ran (and its input-token fee applied).
     *
     * @throws InvalidValue When a count is negative.
     */
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $jevCalls,
        public bool $webSearch,
    ) {
        Guard::nonNegative($inputTokens, 'web context input tokens');
        Guard::nonNegative($outputTokens, 'web context output tokens');
        Guard::nonNegative($jevCalls, 'web context jev calls');
    }

    /**
     * The token part as a regular {@see Usage}, for shared usage accounting.
     *
     * Example:
     * ```php
     * $usage->toUsage()->totalTokens(); // 2152
     * ```
     *
     * @return Usage Input and output tokens, unknown cost.
     */
    public function toUsage(): Usage
    {
        return new Usage($this->inputTokens, $this->outputTokens);
    }
}
