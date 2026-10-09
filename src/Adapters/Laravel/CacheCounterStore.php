<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Sysborg\LaravelJevai\Ports\Driven\CounterStore;

/**
 * {@see CounterStore} on Laravel's cache. `add()` + `increment()` are atomic on the
 * shared stores (Redis, Memcached, database, DynamoDB); use one of them in production.
 */
final readonly class CacheCounterStore implements CounterStore
{
    /**
     * Create the store.
     *
     * Example:
     * ```php
     * new CacheCounterStore(Cache::store('redis'));
     * ```
     *
     * @param  Repository  $cache  The cache store.
     */
    public function __construct(
        private Repository $cache,
    ) {}

    /**
     * Atomically add to a counter, creating it with a lifetime when missing.
     *
     * Example:
     * ```php
     * $store->increment('jev:default:rate:29858018', 1, 120);
     * ```
     *
     * @param  string  $key  Counter key.
     * @param  int  $by  Amount to add.
     * @param  int  $ttlSeconds  Lifetime of a newly created counter.
     * @return int The new value.
     */
    public function increment(string $key, int $by, int $ttlSeconds): int
    {
        $this->cache->add($key, 0, max(1, $ttlSeconds));
        $value = $this->cache->increment($key, $by);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Read a counter.
     *
     * Example:
     * ```php
     * $store->get('jev:default:budget:2026-10-09');
     * ```
     *
     * @param  string  $key  Counter key.
     * @return int The value, 0 when missing.
     */
    public function get(string $key): int
    {
        $value = $this->cache->get($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Set a counter.
     *
     * Example:
     * ```php
     * $store->put('jev:default:circuit:open_until', 1791577110, 30);
     * ```
     *
     * @param  string  $key  Counter key.
     * @param  int  $value  The value.
     * @param  int  $ttlSeconds  Lifetime.
     * @return void Nothing.
     */
    public function put(string $key, int $value, int $ttlSeconds): void
    {
        $this->cache->put($key, $value, max(1, $ttlSeconds));
    }

    /**
     * Delete a counter.
     *
     * Example:
     * ```php
     * $store->forget('jev:default:circuit:failures');
     * ```
     *
     * @param  string  $key  Counter key.
     * @return void Nothing.
     */
    public function forget(string $key): void
    {
        $this->cache->forget($key);
    }
}
