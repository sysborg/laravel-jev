<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Ports\Driven;

/**
 * Small shared key/integer store with expiry, used by the rate limiter, the
 * circuit breaker and the budget guard. Must be shared by every process
 * (web servers, queue workers) for the limits to hold globally.
 */
interface CounterStore
{
    /**
     * Atomically add to a counter, creating it with the given lifetime when missing.
     *
     * Example:
     * ```php
     * $store->increment('jev:default:rate:29858018', 1, 120); // 1, 2, 3, ...
     * ```
     *
     * @param  string  $key  Counter key.
     * @param  int  $by  Amount to add.
     * @param  int  $ttlSeconds  Lifetime of a newly created counter.
     * @return int The new value.
     */
    public function increment(string $key, int $by, int $ttlSeconds): int;

    /**
     * Read a counter.
     *
     * Example:
     * ```php
     * $store->get('jev:default:budget:2026-10-09'); // 48000
     * ```
     *
     * @param  string  $key  Counter key.
     * @return int The value, 0 when missing or expired.
     */
    public function get(string $key): int;

    /**
     * Set a counter.
     *
     * Example:
     * ```php
     * $store->put('jev:default:circuit:open_until', time() + 30, 30);
     * ```
     *
     * @param  string  $key  Counter key.
     * @param  int  $value  The value.
     * @param  int  $ttlSeconds  Lifetime.
     * @return void Nothing.
     */
    public function put(string $key, int $value, int $ttlSeconds): void;

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
    public function forget(string $key): void;
}
