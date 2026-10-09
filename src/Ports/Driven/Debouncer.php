<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Ports\Driven;

/**
 * Lets something happen at most once per time window, across processes
 * (web requests, queue workers), e.g. a low-balance alert.
 *
 * @api
 */
interface Debouncer
{
    /**
     * Claim the key for the window; only the first caller in the window gets true.
     *
     * Implementations must be atomic, so two workers never both get true.
     *
     * Example:
     * ```php
     * if ($debouncer->attempt('jev:balance-low:default', 3600)) {
     *     $events->publish(new BalanceLow(...));
     * }
     * ```
     *
     * @param  string  $key  What is being debounced.
     * @param  int  $seconds  Length of the window.
     * @return bool True for the first claim in the window, false otherwise.
     */
    public function attempt(string $key, int $seconds): bool;
}
