<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Decision;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * Labels sent to Jev's private `trace` field (stored by Jev, not sent to providers).
 *
 * Limited to 64 fields and 8,192 bytes of JSON.
 *
 * @api
 */
final readonly class Trace
{
    public const int MAX_FIELDS = 64;

    public const int MAX_BYTES = 8192;

    /**
     * Wrap already validated fields. Use {@see of()} or {@see empty()} to create instances.
     *
     * Example:
     * ```php
     * Trace::of(['ticket_id' => 42]); // the constructor is private
     * ```
     *
     * @param  array<string, mixed>  $fields  Key => plain value.
     */
    private function __construct(
        private array $fields,
    ) {}

    /**
     * A trace without fields.
     *
     * Example:
     * ```php
     * Trace::empty()->isEmpty(); // true
     * ```
     *
     * @return self The empty trace.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Create a trace from an array.
     *
     * Example:
     * ```php
     * Trace::of(['ticket_id' => 42, 'pipeline' => 'triage-v2']);
     * ```
     *
     * @param  array<string, mixed>  $fields  Key => value made of scalars, null and arrays.
     * @return self The trace.
     *
     * @throws InvalidValue When there are more than 64 fields, a key is blank, a value holds
     *                      objects, INF or NAN, or the JSON is larger than 8,192 bytes.
     */
    public static function of(array $fields): self
    {
        if (count($fields) > self::MAX_FIELDS) {
            throw InvalidValue::because('trace', 'must have at most '.self::MAX_FIELDS.' fields');
        }

        foreach ($fields as $key => $value) {
            Guard::notBlank((string) $key, 'trace key');
            Guard::plainData($value, "trace [{$key}]");
        }

        if ($fields !== [] && strlen(Guard::json($fields, 'trace')) > self::MAX_BYTES) {
            throw InvalidValue::because('trace', 'must be at most '.self::MAX_BYTES.' bytes once JSON encoded');
        }

        return new self($fields);
    }

    /**
     * Return a new trace with one field set.
     *
     * Example:
     * ```php
     * $trace = $trace->with('correlation_id', (string) $correlationId);
     * ```
     *
     * @param  string  $key  The field to set.
     * @param  mixed  $value  A scalar, null or array of those.
     * @return self A new trace; the original is unchanged.
     *
     * @throws InvalidValue When a limit of {@see of()} would be broken.
     */
    public function with(string $key, mixed $value): self
    {
        return self::of([...$this->fields, $key => $value]);
    }

    /**
     * Return a new trace with several fields set, overriding existing keys.
     *
     * Example:
     * ```php
     * $trace = $trace->merge(['tenant' => 'acme', 'release' => '1.4.0']);
     * ```
     *
     * @param  array<string, mixed>  $fields  Key => value made of scalars, null and arrays.
     * @return self A new trace; the original is unchanged.
     *
     * @throws InvalidValue When a limit of {@see of()} would be broken.
     */
    public function merge(array $fields): self
    {
        return self::of([...$this->fields, ...$fields]);
    }

    /**
     * Return a new trace without the given fields, e.g. to drop PII before sending.
     *
     * Example:
     * ```php
     * $trace = $trace->without(['email', 'phone']);
     * ```
     *
     * @param  list<string>  $keys  Fields to remove; missing keys are ignored.
     * @return self A new trace; the original is unchanged.
     */
    public function without(array $keys): self
    {
        return new self(array_diff_key($this->fields, array_flip($keys)));
    }

    /**
     * Read a field.
     *
     * Example:
     * ```php
     * $trace->get('ticket_id'); // 42
     * ```
     *
     * @param  string  $key  The field to read.
     * @param  mixed  $default  Returned when the field is missing.
     * @return mixed The value, or `$default`.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->fields) ? $this->fields[$key] : $default;
    }

    /**
     * Whether a field is set (even to null).
     *
     * Example:
     * ```php
     * $trace->has('correlation_id'); // true
     * ```
     *
     * @param  string  $key  The field to look for.
     * @return bool True when the field exists.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->fields);
    }

    /**
     * Whether the trace has no fields.
     *
     * Example:
     * ```php
     * Trace::empty()->isEmpty(); // true
     * ```
     *
     * @return bool True when empty.
     */
    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * All fields as an array, as sent to Jev.
     *
     * Example:
     * ```php
     * $trace->toArray(); // ['ticket_id' => 42]
     * ```
     *
     * @return array<string, mixed> Key => value.
     */
    public function toArray(): array
    {
        return $this->fields;
    }
}
