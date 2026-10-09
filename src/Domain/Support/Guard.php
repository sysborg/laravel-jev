<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Support;

use JsonException;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;

/**
 * Invariant checks shared by the domain value objects.
 *
 * @internal
 */
final class Guard
{
    /**
     * Probabilities coming back from the API may drift by floating point noise.
     */
    private const float PROBABILITY_TOLERANCE = 1e-6;

    /**
     * Ensure a string contains something other than whitespace.
     *
     * Example:
     * ```php
     * Guard::notBlank($instructions, 'question instructions'); // returns $instructions
     * Guard::notBlank('  ', 'question instructions');          // throws InvalidValue
     * ```
     *
     * @param  string  $value  Value to check.
     * @param  string  $field  Field name used in the error message.
     * @return string The unchanged value.
     *
     * @throws InvalidValue When the value is empty or only whitespace.
     */
    public static function notBlank(string $value, string $field): string
    {
        if (trim($value) === '') {
            throw InvalidValue::because($field, 'must not be blank');
        }

        return $value;
    }

    /**
     * Ensure a string has at most `$max` characters (multibyte aware).
     *
     * Example:
     * ```php
     * Guard::maxLength($sessionId, 256, 'session id');
     * ```
     *
     * @param  string  $value  Value to check.
     * @param  int  $max  Maximum number of characters allowed.
     * @param  string  $field  Field name used in the error message.
     * @return string The unchanged value.
     *
     * @throws InvalidValue When the value is longer than `$max` characters.
     */
    public static function maxLength(string $value, int $max, string $field): string
    {
        if (mb_strlen($value) > $max) {
            throw InvalidValue::because($field, "must be at most {$max} characters");
        }

        return $value;
    }

    /**
     * Ensure a string is a non-blank identifier of at most `$max` characters.
     *
     * Example:
     * ```php
     * Guard::identifier('is_urgent', 64, 'question id');
     * ```
     *
     * @param  string  $value  Value to check.
     * @param  int  $max  Maximum number of characters allowed.
     * @param  string  $field  Field name used in the error message.
     * @return string The unchanged value.
     *
     * @throws InvalidValue When the value is blank or too long.
     */
    public static function identifier(string $value, int $max, string $field): string
    {
        return self::maxLength(self::notBlank($value, $field), $max, $field);
    }

    /**
     * Same as {@see identifier()}, but null is accepted as "not set".
     *
     * Example:
     * ```php
     * Guard::nullableIdentifier(null, 256, 'user');     // null
     * Guard::nullableIdentifier('user-7', 256, 'user'); // 'user-7'
     * ```
     *
     * @param  string|null  $value  Value to check, or null.
     * @param  int  $max  Maximum number of characters allowed.
     * @param  string  $field  Field name used in the error message.
     * @return string|null The unchanged value.
     *
     * @throws InvalidValue When the value is a blank or too long string.
     */
    public static function nullableIdentifier(?string $value, int $max, string $field): ?string
    {
        return $value === null ? null : self::identifier($value, $max, $field);
    }

    /**
     * Ensure an integer lies within an inclusive range.
     *
     * Example:
     * ```php
     * Guard::between(count($levels), 2, 10, 'level count');
     * ```
     *
     * @param  int  $value  Value to check.
     * @param  int  $min  Smallest allowed value.
     * @param  int  $max  Largest allowed value.
     * @param  string  $field  Field name used in the error message.
     * @return int The unchanged value.
     *
     * @throws InvalidValue When the value is lower than `$min` or greater than `$max`.
     */
    public static function between(int $value, int $min, int $max, string $field): int
    {
        if ($value < $min || $value > $max) {
            throw InvalidValue::because($field, "must be between {$min} and {$max}, got {$value}");
        }

        return $value;
    }

    /**
     * Ensure an integer is zero or positive.
     *
     * Example:
     * ```php
     * Guard::nonNegative($inputTokens, 'usage input tokens');
     * ```
     *
     * @param  int  $value  Value to check.
     * @param  string  $field  Field name used in the error message.
     * @return int The unchanged value.
     *
     * @throws InvalidValue When the value is negative.
     */
    public static function nonNegative(int $value, string $field): int
    {
        if ($value < 0) {
            throw InvalidValue::because($field, 'must not be negative');
        }

        return $value;
    }

    /**
     * Ensure a float is finite and zero or positive.
     *
     * Example:
     * ```php
     * Guard::nonNegativeFloat($creditsCharged, 'billing credits charged');
     * ```
     *
     * @param  float  $value  Value to check.
     * @param  string  $field  Field name used in the error message.
     * @return float The unchanged value.
     *
     * @throws InvalidValue When the value is negative, INF or NAN.
     */
    public static function nonNegativeFloat(float $value, string $field): float
    {
        if (! is_finite($value) || $value < 0) {
            throw InvalidValue::because($field, 'must be a finite, non-negative number');
        }

        return $value;
    }

    /**
     * Ensure a float is a probability, clamping tiny floating point drift into 0..1.
     *
     * Example:
     * ```php
     * Guard::probability(1.0000001, 'noul'); // 1.0
     * Guard::probability(1.5, 'noul');       // throws InvalidValue
     * ```
     *
     * @param  float  $value  Value to check.
     * @param  string  $field  Field name used in the error message.
     * @return float The value clamped into 0..1.
     *
     * @throws InvalidValue When the value is INF, NAN or outside 0..1 beyond the tolerance.
     */
    public static function probability(float $value, string $field): float
    {
        if (! is_finite($value)
            || $value < -self::PROBABILITY_TOLERANCE
            || $value > 1 + self::PROBABILITY_TOLERANCE) {
            throw InvalidValue::because($field, 'must be a probability between 0 and 1');
        }

        return max(0.0, min(1.0, $value));
    }

    /**
     * Validate a map of probabilities, keeping its keys.
     *
     * Example:
     * ```php
     * Guard::probabilities(['billing' => 0.99, 'sales' => 0], 'probabilities');
     * // ['billing' => 0.99, 'sales' => 0.0]
     * ```
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $values  Key => probability.
     * @param  string  $field  Field name used in the error message.
     * @return array<TKey, float> The same keys with values cast to clamped floats.
     *
     * @throws InvalidValue When a value is not a number or not a probability.
     */
    public static function probabilities(array $values, string $field): array
    {
        $probabilities = [];

        foreach ($values as $key => $value) {
            if (! is_int($value) && ! is_float($value)) {
                throw InvalidValue::because("{$field} [{$key}]", 'must be a number');
            }

            $probabilities[$key] = self::probability((float) $value, "{$field} [{$key}]");
        }

        return $probabilities;
    }

    /**
     * Ensure a value only holds scalars, null and arrays.
     *
     * Such values can be JSON encoded, serialized into queued jobs and stored safely.
     *
     * Example:
     * ```php
     * Guard::plainData(['ticket_id' => 42, 'tags' => ['p1']], 'context'); // ok
     * Guard::plainData(['user' => $userModel], 'context');               // throws InvalidValue
     * ```
     *
     * @param  mixed  $value  Value to check, recursively.
     * @param  string  $field  Field name used in the error message.
     * @return void Nothing; the method only throws on invalid data.
     *
     * @throws InvalidValue When an object, resource, INF or NAN is found.
     */
    public static function plainData(mixed $value, string $field): void
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw InvalidValue::because($field, 'must not contain INF or NAN');
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::plainData($item, $field);
            }

            return;
        }

        throw InvalidValue::because($field, 'may only contain scalars, null and arrays');
    }

    /**
     * JSON encode a value the same way it will be sent to Jev.
     *
     * Example:
     * ```php
     * Guard::json(['url' => 'https://a.b/c', 'score' => 1.0], 'trace');
     * // '{"url":"https://a.b/c","score":1.0}'
     * ```
     *
     * @param  mixed  $value  Value to encode.
     * @param  string  $field  Field name used in the error message.
     * @return string The JSON document, with unescaped slashes and unicode.
     *
     * @throws InvalidValue When the value cannot be JSON encoded (e.g. invalid UTF-8).
     */
    public static function json(mixed $value, string $field): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $e) {
            throw InvalidValue::because($field, 'must be JSON encodable ('.$e->getMessage().')');
        }
    }
}
