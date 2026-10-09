<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Decision;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * Application data attached to a call and to every event it emits.
 *
 * Never sent to Jev. Use it to route results back to your models,
 * e.g. ['ticket_id' => 42]. Holds only scalars, null and arrays so
 * events stay serializable for queued listeners.
 *
 * @api
 */
final readonly class Context
{
    /**
     * Wrap already validated values. Use {@see of()} or {@see empty()} to create instances.
     *
     * Example:
     * ```php
     * Context::of(['ticket_id' => 42]); // the constructor is private
     * ```
     *
     * @param  array<string, mixed>  $values  Key => plain value.
     */
    private function __construct(
        private array $values,
    ) {}

    /**
     * A context without values.
     *
     * Example:
     * ```php
     * Context::empty()->isEmpty(); // true
     * ```
     *
     * @return self The empty context.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Create a context from an array.
     *
     * Example:
     * ```php
     * Context::of(['ticket_id' => $ticket->id, 'source' => 'email']);
     * ```
     *
     * @param  array<string, mixed>  $values  Key => value made of scalars, null and arrays.
     * @return self The context.
     *
     * @throws InvalidValue When a key is blank or a value holds objects, INF or NAN.
     */
    public static function of(array $values): self
    {
        foreach ($values as $key => $value) {
            Guard::notBlank((string) $key, 'context key');
            Guard::plainData($value, "context [{$key}]");
        }

        return new self($values);
    }

    /**
     * Return a new context with one value set.
     *
     * Example:
     * ```php
     * $context = $context->with('tenant', 'acme');
     * ```
     *
     * @param  string  $key  The key to set.
     * @param  mixed  $value  A scalar, null or array of those.
     * @return self A new context; the original is unchanged.
     *
     * @throws InvalidValue When the key is blank or the value holds objects, INF or NAN.
     */
    public function with(string $key, mixed $value): self
    {
        return self::of([...$this->values, $key => $value]);
    }

    /**
     * Return a new context with several values set, overriding existing keys.
     *
     * Example:
     * ```php
     * $context = $context->merge(['tenant' => 'acme', 'channel' => 'chat']);
     * ```
     *
     * @param  array<string, mixed>  $values  Key => value made of scalars, null and arrays.
     * @return self A new context; the original is unchanged.
     *
     * @throws InvalidValue When a key is blank or a value holds objects, INF or NAN.
     */
    public function merge(array $values): self
    {
        return self::of([...$this->values, ...$values]);
    }

    /**
     * Read a value.
     *
     * Example:
     * ```php
     * $ticketId = $event->context->get('ticket_id');
     * ```
     *
     * @param  string  $key  The key to read.
     * @param  mixed  $default  Returned when the key is missing.
     * @return mixed The value, or `$default`.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    /**
     * Whether a key is set (even to null).
     *
     * Example:
     * ```php
     * $context->has('ticket_id'); // true
     * ```
     *
     * @param  string  $key  The key to look for.
     * @return bool True when the key exists.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * Whether the context has no values.
     *
     * Example:
     * ```php
     * Context::empty()->isEmpty(); // true
     * ```
     *
     * @return bool True when empty.
     */
    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * All values as an array.
     *
     * Example:
     * ```php
     * $context->toArray(); // ['ticket_id' => 42]
     * ```
     *
     * @return array<string, mixed> Key => value.
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
