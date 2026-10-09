<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Laravel;

use Illuminate\Contracts\Config\Repository;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;

/**
 * Typed reads of the `jev.*` configuration, tolerant of env() strings.
 */
final readonly class Settings
{
    /**
     * Create the reader.
     *
     * Example:
     * ```php
     * $settings = new Settings(app('config'));
     * ```
     *
     * @param  Repository  $config  Laravel's config repository.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Read a boolean; accepts true/false, 1/0 and "true"/"false"/"1"/"0"/"on"/"off".
     *
     * Example:
     * ```php
     * $settings->bool('events.enabled', true);
     * ```
     *
     * @param  string  $key  Key under `jev.`.
     * @param  bool  $default  Used when the key is missing or null.
     * @return bool The value.
     *
     * @throws InvalidValue When the value is not a boolean.
     */
    public function bool(string $key, bool $default): bool
    {
        $value = $this->config->get("jev.{$key}");

        if ($value === null) {
            return $default;
        }

        $parsed = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $parsed ?? throw InvalidValue::because("config jev.{$key}", 'must be a boolean');
    }

    /**
     * Read a non-negative integer; numeric strings are accepted.
     *
     * Example:
     * ```php
     * $settings->int('retry.max_attempts', 3);
     * ```
     *
     * @param  string  $key  Key under `jev.`.
     * @param  int  $default  Used when the key is missing or null.
     * @return int The value.
     *
     * @throws InvalidValue When the value is not a non-negative integer.
     */
    public function int(string $key, int $default): int
    {
        $value = $this->config->get("jev.{$key}");

        return match (true) {
            $value === null => $default,
            is_int($value) && $value >= 0 => $value,
            is_string($value) && preg_match('/^\d+$/', $value) === 1 => (int) $value,
            default => throw InvalidValue::because("config jev.{$key}", 'must be a non-negative integer'),
        };
    }

    /**
     * Read an optional non-negative integer; numeric strings are accepted, empty strings count as null.
     *
     * Example:
     * ```php
     * $settings->nullableInt('alerts.tokens_remaining_threshold'); // null or 100000
     * ```
     *
     * @param  string  $key  Key under `jev.`.
     * @return int|null The value, or null.
     *
     * @throws InvalidValue When the value is not a non-negative integer.
     */
    public function nullableInt(string $key): ?int
    {
        $value = $this->config->get("jev.{$key}");

        return $value === null || $value === '' ? null : $this->int($key, 0);
    }

    /**
     * Read a string.
     *
     * Example:
     * ```php
     * $settings->string('redaction.state', 'truncate:200');
     * ```
     *
     * @param  string  $key  Key under `jev.`.
     * @param  string  $default  Used when the key is missing, null or empty.
     * @return string The value.
     *
     * @throws InvalidValue When the value is not a string.
     */
    public function string(string $key, string $default): string
    {
        return $this->nullableString($key) ?? $default;
    }

    /**
     * Read an optional string; empty strings count as null.
     *
     * Example:
     * ```php
     * $settings->nullableString('queue.connection'); // null or 'redis'
     * ```
     *
     * @param  string  $key  Key under `jev.`.
     * @return string|null The value, or null.
     *
     * @throws InvalidValue When the value is not a string.
     */
    public function nullableString(string $key): ?string
    {
        $value = $this->config->get("jev.{$key}");

        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : throw InvalidValue::because("config jev.{$key}", 'must be a string');
    }

    /**
     * Read a list of strings; a comma separated string is accepted.
     *
     * Example:
     * ```php
     * $settings->stringList('redaction.trace_deny'); // ['email', 'phone']
     * ```
     *
     * @param  string  $key  Key under `jev.`.
     * @return list<string> The values, trimmed and without empties.
     *
     * @throws InvalidValue When the value is neither a list nor a string.
     */
    public function stringList(string $key): array
    {
        $value = $this->config->get("jev.{$key}");

        $items = match (true) {
            $value === null => [],
            is_string($value) => explode(',', $value),
            is_array($value) => $value,
            default => throw InvalidValue::because("config jev.{$key}", 'must be a list of strings'),
        };

        $list = [];

        foreach ($items as $item) {
            if (is_string($item) && trim($item) !== '') {
                $list[] = trim($item);
            }
        }

        return $list;
    }
}
