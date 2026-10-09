<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Decision;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * The input Jev decides on: free text or structured data.
 *
 * @api
 */
final readonly class State
{
    /**
     * Wrap an already validated value. Use {@see of()} to create instances.
     *
     * Example:
     * ```php
     * State::of('Server is down'); // the constructor is private
     * ```
     *
     * @param  string|array<array-key, mixed>  $value  Text or plain structured data.
     */
    private function __construct(
        public string|array $value,
    ) {}

    /**
     * Create a state from text or structured data.
     *
     * Example:
     * ```php
     * State::of('My card was charged twice!');
     * State::of(['subject' => 'Refund', 'body' => 'Charged twice', 'plan' => 'pro']);
     * ```
     *
     * @param  string|array<array-key, mixed>  $value  Text, or an array of scalars, null and arrays.
     * @return self The state.
     *
     * @throws InvalidValue When the text is blank, the array is empty, or the array holds objects, INF or NAN.
     */
    public static function of(string|array $value): self
    {
        if (is_string($value)) {
            Guard::notBlank($value, 'state');
        } else {
            if ($value === []) {
                throw InvalidValue::because('state', 'must not be empty');
            }

            Guard::plainData($value, 'state');
        }

        return new self($value);
    }

    /**
     * Whether the state is free text rather than structured data.
     *
     * Example:
     * ```php
     * State::of('hello')->isText();      // true
     * State::of(['a' => 1])->isText();   // false
     * ```
     *
     * @return bool True for text.
     */
    public function isText(): bool
    {
        return is_string($this->value);
    }

    /**
     * The state encoded as JSON, as it is sent to Jev.
     *
     * Example:
     * ```php
     * State::of(['a' => 1])->toJson(); // '{"a":1}'
     * ```
     *
     * @return string The JSON document.
     *
     * @throws InvalidValue When the state cannot be JSON encoded (e.g. invalid UTF-8).
     */
    public function toJson(): string
    {
        return Guard::json($this->value, 'state');
    }
}
