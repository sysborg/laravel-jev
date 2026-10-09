<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use LogicException;
use SensitiveParameter;
use Stringable;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use WeakMap;

/**
 * A Jev API key that cannot leak by accident.
 *
 * It prints as `[redacted]`, hides itself from `var_dump()` / `dd()`, is
 * omitted from JSON and refuses to be serialized (so it never ends up in a
 * queued job). The raw value is not even a property of the object: it lives
 * in a private static map, so `var_export()`, `print_r()` and `(array)` casts
 * cannot reach it either. Only {@see reveal()} returns the real value.
 */
final class ApiKey implements \JsonSerializable, Stringable
{
    private const string MASK = '[redacted]';

    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $values = null;

    /**
     * Wrap a key.
     *
     * Example:
     * ```php
     * $key = new ApiKey((string) env('JEV_AI_API_KEY'));
     * ```
     *
     * @param  string  $value  The raw key; hidden from stack traces.
     *
     * @throws InvalidValue When the key is blank.
     */
    public function __construct(
        #[SensitiveParameter]
        string $value,
    ) {
        if (trim($value) === '') {
            throw InvalidValue::because('Jev API key', 'must not be blank');
        }

        self::values()[$this] = $value;
    }

    /**
     * The real key, for the Authorization header only.
     *
     * Example:
     * ```php
     * $http->withToken($key->reveal());
     * ```
     *
     * @return string The raw key.
     */
    public function reveal(): string
    {
        return self::values()[$this];
    }

    /**
     * The masked key.
     *
     * Example:
     * ```php
     * echo $key; // [redacted]
     * ```
     *
     * @return string Always `[redacted]`.
     */
    public function __toString(): string
    {
        return self::MASK;
    }

    /**
     * What `var_dump()`, `dd()` and `print_r()` show.
     *
     * Example:
     * ```php
     * dd($key); // ApiKey { value: "[redacted]" }
     * ```
     *
     * @return array{value: string} The masked value.
     */
    public function __debugInfo(): array
    {
        return ['value' => self::MASK];
    }

    /**
     * What `json_encode()` outputs.
     *
     * Example:
     * ```php
     * json_encode(['key' => $key]); // {"key":"[redacted]"}
     * ```
     *
     * @return string Always `[redacted]`.
     */
    public function jsonSerialize(): string
    {
        return self::MASK;
    }

    /**
     * Refuse serialization, so the key can never be written into a queued job or cache.
     *
     * Example:
     * ```php
     * serialize($key); // throws LogicException
     * ```
     *
     * @return array<string, mixed> Never returns.
     *
     * @throws LogicException Always.
     */
    public function __serialize(): array
    {
        throw new LogicException('A Jev API key cannot be serialized. Resolve it from config at runtime instead.');
    }

    /**
     * Forbid cloning: a clone would not be registered in the value map.
     *
     * Example:
     * ```php
     * clone $key; // Error: Call to private ApiKey::__clone()
     * ```
     *
     * @return void Nothing.
     */
    private function __clone(): void {}

    /**
     * The process-wide map holding the raw values, keyed by instance.
     *
     * Example:
     * ```php
     * self::values()[$this] = $value;
     * ```
     *
     * @return WeakMap<self, string> The map; entries vanish with their instance.
     */
    private static function values(): WeakMap
    {
        return self::$values ??= new WeakMap;
    }
}
