<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use Illuminate\Http\Client\Response;
use Sysborg\LaravelJevai\Domain\Exceptions\UnexpectedResponse;

/**
 * Typed, path-aware access to a decoded Jev response body.
 *
 * Unknown fields are ignored; missing or mistyped required fields raise
 * {@see UnexpectedResponse} naming the field path (never its value).
 */
final readonly class ResponseReader
{
    /**
     * Wrap decoded data. Use {@see fromResponse()} or {@see of()}.
     *
     * Example:
     * ```php
     * ResponseReader::of(['usage' => ['input_tokens' => 120]], status: 200);
     * ```
     *
     * @param  array<array-key, mixed>  $data  Decoded JSON object.
     * @param  string  $path  Dotted path of this object in the body, '' for the root.
     * @param  int  $status  HTTP status, attached to raised exceptions.
     */
    private function __construct(
        private array $data,
        private string $path,
        private int $status,
    ) {}

    /**
     * Decode a response body that must be a JSON object.
     *
     * Example:
     * ```php
     * $body = ResponseReader::fromResponse($response);
     * ```
     *
     * @param  Response  $response  The HTTP response.
     * @return self A reader over the root object.
     *
     * @throws UnexpectedResponse When the body is not a JSON object.
     */
    public static function fromResponse(Response $response): self
    {
        $data = json_decode($response->body(), true);

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new UnexpectedResponse('Jev returned a body that is not a JSON object.', $response->status());
        }

        return new self($data, '', $response->status());
    }

    /**
     * Wrap already decoded data.
     *
     * Example:
     * ```php
     * $reader = ResponseReader::of(['creditsRemaining' => 42], status: 200);
     * ```
     *
     * @param  array<array-key, mixed>  $data  Decoded JSON object.
     * @param  string  $path  Dotted path of the object in the body, '' for the root.
     * @param  int  $status  HTTP status, attached to raised exceptions.
     * @return self The reader.
     */
    public static function of(array $data, string $path = '', int $status = 200): self
    {
        return new self($data, $path, $status);
    }

    /**
     * Whether a field is present and not null.
     *
     * Example:
     * ```php
     * $body->has('billing'); // true
     * ```
     *
     * @param  string  $key  Field name.
     * @return bool True when present and not null.
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Read a required string.
     *
     * Example:
     * ```php
     * $answer->string('choice'); // 'billing'
     * ```
     *
     * @param  string  $key  Field name.
     * @return string The value.
     *
     * @throws UnexpectedResponse When the field is missing or not a string.
     */
    public function string(string $key): string
    {
        return $this->optionalString($key) ?? throw $this->missing($key, 'a string');
    }

    /**
     * Read an optional string.
     *
     * Example:
     * ```php
     * $body->optionalString('provider'); // null when absent
     * ```
     *
     * @param  string  $key  Field name.
     * @return string|null The value, or null when absent.
     *
     * @throws UnexpectedResponse When the field is present but not a string.
     */
    public function optionalString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw $this->invalid($key, 'a string');
    }

    /**
     * Read a required number.
     *
     * Example:
     * ```php
     * $answer->float('noul'); // 0.95
     * ```
     *
     * @param  string  $key  Field name.
     * @return float The value; JSON integers are accepted.
     *
     * @throws UnexpectedResponse When the field is missing or not a number.
     */
    public function float(string $key): float
    {
        return $this->optionalFloat($key) ?? throw $this->missing($key, 'a number');
    }

    /**
     * Read an optional number.
     *
     * Example:
     * ```php
     * $answer->optionalFloat('confidence'); // 0.98 or null
     * ```
     *
     * @param  string  $key  Field name.
     * @return float|null The value, or null when absent.
     *
     * @throws UnexpectedResponse When the field is present but not a number.
     */
    public function optionalFloat(string $key): ?float
    {
        $value = $this->data[$key] ?? null;

        return match (true) {
            $value === null => null,
            is_int($value), is_float($value) => (float) $value,
            default => throw $this->invalid($key, 'a number'),
        };
    }

    /**
     * Read an optional integer; whole floats such as `120.0` are accepted.
     *
     * Example:
     * ```php
     * $usage->optionalInt('input_tokens'); // 120
     * ```
     *
     * @param  string  $key  Field name.
     * @return int|null The value, or null when absent.
     *
     * @throws UnexpectedResponse When the field is present but not an integer.
     */
    public function optionalInt(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        return match (true) {
            $value === null => null,
            is_int($value) => $value,
            is_float($value) && floor($value) === $value => (int) $value,
            default => throw $this->invalid($key, 'an integer'),
        };
    }

    /**
     * Read an optional boolean.
     *
     * Example:
     * ```php
     * $usage->optionalBool('web_search'); // true
     * ```
     *
     * @param  string  $key  Field name.
     * @return bool|null The value, or null when absent.
     *
     * @throws UnexpectedResponse When the field is present but not a boolean.
     */
    public function optionalBool(string $key): ?bool
    {
        $value = $this->data[$key] ?? null;

        if ($value === null || is_bool($value)) {
            return $value;
        }

        throw $this->invalid($key, 'a boolean');
    }

    /**
     * Read a required JSON object as a nested reader.
     *
     * Example:
     * ```php
     * $answers = $body->object('answers');
     * ```
     *
     * @param  string  $key  Field name.
     * @return self A reader over the nested object.
     *
     * @throws UnexpectedResponse When the field is missing or not an object.
     */
    public function object(string $key): self
    {
        return $this->optionalObject($key) ?? throw $this->missing($key, 'an object');
    }

    /**
     * Read an optional JSON object as a nested reader.
     *
     * JSON objects with keys "0", "1", ... decode to PHP lists, so lists are accepted too.
     *
     * Example:
     * ```php
     * $billing = $body->optionalObject('billing'); // null when absent
     * ```
     *
     * @param  string  $key  Field name.
     * @return self|null A reader over the nested object, or null when absent.
     *
     * @throws UnexpectedResponse When the field is present but not an object.
     */
    public function optionalObject(string $key): ?self
    {
        $value = $this->data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        // PHP decodes {"0": ..., "1": ...} into a list, so lists are accepted as objects.
        if (! is_array($value)) {
            throw $this->invalid($key, 'an object');
        }

        return new self($value, $this->pathOf($key), $this->status);
    }

    /**
     * Read an optional JSON array (list).
     *
     * Example:
     * ```php
     * $body->optionalList('sources'); // [[...], [...]]
     * ```
     *
     * @param  string  $key  Field name.
     * @return list<mixed>|null The items, or null when absent.
     *
     * @throws UnexpectedResponse When the field is present but not a list.
     */
    public function optionalList(string $key): ?array
    {
        $value = $this->data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->invalid($key, 'a list');
        }

        return $value;
    }

    /**
     * Read an optional map of numbers, e.g. probabilities.
     *
     * Example:
     * ```php
     * $answer->optionalNumberMap('probabilities'); // ['billing' => 0.99, 'sales' => 0.0]
     * ```
     *
     * @param  string  $key  Field name.
     * @return array<array-key, int|float> Key => number; empty when absent.
     *
     * @throws UnexpectedResponse When the field is not an object of numbers.
     */
    public function optionalNumberMap(string $key): array
    {
        $object = $this->optionalObject($key);

        if ($object === null) {
            return [];
        }

        $map = [];

        foreach ($object->data as $item => $value) {
            if (! is_int($value) && ! is_float($value)) {
                throw $object->invalid((string) $item, 'a number');
            }

            $map[$item] = $value;
        }

        return $map;
    }

    /**
     * Read an optional map of strings, e.g. a score legend.
     *
     * Example:
     * ```php
     * $answer->optionalStringMap('legend'); // [0 => 'Calm', 1 => 'Frustrated']
     * ```
     *
     * @param  string  $key  Field name.
     * @return array<array-key, string> Key => string; empty when absent.
     *
     * @throws UnexpectedResponse When the field is not an object of strings.
     */
    public function optionalStringMap(string $key): array
    {
        $object = $this->optionalObject($key);

        if ($object === null) {
            return [];
        }

        $map = [];

        foreach ($object->data as $item => $value) {
            if (! is_string($value)) {
                throw $object->invalid((string) $item, 'a string');
            }

            $map[$item] = $value;
        }

        return $map;
    }

    /**
     * Names of the fields of this object, as strings.
     *
     * Example:
     * ```php
     * $body->object('answers')->keys(); // ['is_urgent', 'department']
     * ```
     *
     * @return list<string> The field names.
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->data));
    }

    /**
     * The decoded data of this object.
     *
     * Example:
     * ```php
     * $body->toArray(); // ['model' => 'jev-latest', ...]
     * ```
     *
     * @return array<array-key, mixed> The data.
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * HTTP status of the response being read.
     *
     * Example:
     * ```php
     * $body->status(); // 200
     * ```
     *
     * @return int The status.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Build the exception for a field that is present with the wrong type.
     *
     * Example:
     * ```php
     * throw $this->invalid('noul', 'a number');
     * // "Jev response field [answers.is_urgent.noul] must be a number."
     * ```
     *
     * @param  string  $key  Field name.
     * @param  string  $expected  Expected type, e.g. "a number".
     * @return UnexpectedResponse The exception, ready to be thrown.
     */
    public function invalid(string $key, string $expected): UnexpectedResponse
    {
        return new UnexpectedResponse("Jev response field [{$this->pathOf($key)}] must be {$expected}.", $this->status);
    }

    /**
     * Build the exception for a missing required field.
     *
     * Example:
     * ```php
     * throw $this->missing('answers', 'an object');
     * // "Jev response field [answers] is missing; expected an object."
     * ```
     *
     * @param  string  $key  Field name.
     * @param  string  $expected  Expected type, e.g. "an object".
     * @return UnexpectedResponse The exception, ready to be thrown.
     */
    private function missing(string $key, string $expected): UnexpectedResponse
    {
        return new UnexpectedResponse("Jev response field [{$this->pathOf($key)}] is missing; expected {$expected}.", $this->status);
    }

    /**
     * Dotted path of a field of this object.
     *
     * Example:
     * ```php
     * $this->pathOf('noul'); // 'answers.is_urgent.noul'
     * ```
     *
     * @param  string  $key  Field name.
     * @return string The path.
     */
    private function pathOf(string $key): string
    {
        return $this->path === '' ? $key : "{$this->path}.{$key}";
    }
}
