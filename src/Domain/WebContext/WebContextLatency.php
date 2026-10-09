<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\WebContext;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * Server-side time split of a web-context request, as reported by Jev.
 *
 * @api
 */
final readonly class WebContextLatency
{
    /**
     * Create the latency.
     *
     * Example:
     * ```php
     * new WebContextLatency(searchMs: 560, jevMs: 410);
     * ```
     *
     * @param  int  $searchMs  Time spent searching the web, in milliseconds.
     * @param  int  $jevMs  Time spent in Jev evaluations, in milliseconds.
     *
     * @throws InvalidValue When a duration is negative.
     */
    public function __construct(
        public int $searchMs,
        public int $jevMs,
    ) {
        Guard::nonNegative($searchMs, 'web context search latency');
        Guard::nonNegative($jevMs, 'web context jev latency');
    }

    /**
     * Search plus evaluation time.
     *
     * Example:
     * ```php
     * (new WebContextLatency(560, 410))->totalMs(); // 970
     * ```
     *
     * @return int The total in milliseconds.
     */
    public function totalMs(): int
    {
        return $this->searchMs + $this->jevMs;
    }
}
