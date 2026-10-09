<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Sysborg\LaravelJevai\Ports\Driven\Debouncer;

/**
 * {@see Debouncer} on Laravel's cache: `add()` only writes a missing key, atomically
 * on the shared stores (Redis, Memcached, database, DynamoDB).
 */
final readonly class CacheDebouncer implements Debouncer
{
    /**
     * Create the debouncer.
     *
     * Example:
     * ```php
     * new CacheDebouncer(Cache::store('redis'));
     * ```
     *
     * @param  Repository  $cache  The cache store; use a shared one when several servers run workers.
     */
    public function __construct(
        private Repository $cache,
    ) {}

    /**
     * Claim the key for the window.
     *
     * Example:
     * ```php
     * $debouncer->attempt('jev:balance-low:default', 3600); // true once per hour
     * ```
     *
     * @param  string  $key  What is being debounced.
     * @param  int  $seconds  Length of the window.
     * @return bool True for the first claim in the window, false otherwise.
     */
    public function attempt(string $key, int $seconds): bool
    {
        return $this->cache->add($key, true, max(1, $seconds));
    }
}
