<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Support;

use Sysborg\LaravelJevai\Domain\Decision\Trace;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;

/**
 * Decides what user data leaves the call:
 *
 * - the state preview attached to events, logs and spans (`none`, `truncate:N`, `hash`, `omit`);
 * - the trace keys that must never be sent to Jev (deny list).
 */
final readonly class Redactor
{
    public const string FULL = 'none';

    public const string TRUNCATE = 'truncate';

    public const string HASH = 'hash';

    public const string OMIT = 'omit';

    /**
     * Create the redactor.
     *
     * Example:
     * ```php
     * new Redactor(Redactor::TRUNCATE, 200, ['email', 'phone']);
     * ```
     *
     * @param  string  $strategy  One of `none` (keep everything), `truncate`, `hash` or `omit`.
     * @param  int  $length  Characters kept by `truncate`.
     * @param  list<string>  $traceDenyKeys  Trace fields removed before the request is sent.
     *
     * @throws InvalidValue When the strategy is unknown or the length is lower than 1.
     */
    public function __construct(
        public string $strategy = self::TRUNCATE,
        public int $length = 200,
        public array $traceDenyKeys = [],
    ) {
        if (! in_array($strategy, [self::FULL, self::TRUNCATE, self::HASH, self::OMIT], true)) {
            throw InvalidValue::because('redaction strategy', 'must be one of none, truncate:N, hash or omit');
        }

        if ($length < 1) {
            throw InvalidValue::because('redaction truncate length', 'must be at least 1');
        }
    }

    /**
     * Build a redactor from a config spec such as `truncate:200`.
     *
     * Example:
     * ```php
     * Redactor::fromSpec('hash', ['email']);
     * Redactor::fromSpec('truncate:80');
     * ```
     *
     * @param  string  $spec  `none`, `hash`, `omit` or `truncate[:N]` (N defaults to 200).
     * @param  list<string>  $traceDenyKeys  Trace fields removed before the request is sent.
     * @return self The redactor.
     *
     * @throws InvalidValue When the spec is unknown or N is not a positive integer.
     */
    public static function fromSpec(string $spec, array $traceDenyKeys = []): self
    {
        [$strategy, $length] = array_pad(explode(':', trim(strtolower($spec)), 2), 2, null);

        if ($length !== null && preg_match('/^\d+$/', $length) !== 1) {
            throw InvalidValue::because('redaction truncate length', 'must be a positive integer');
        }

        return new self($strategy ?? self::TRUNCATE, $length === null ? 200 : (int) $length, $traceDenyKeys);
    }

    /**
     * The observable form of a state or question.
     *
     * Example:
     * ```php
     * (new Redactor('truncate', 10))->preview('My card was charged twice'); // 'My card wa…'
     * (new Redactor('hash'))->preview('secret');                          // 'sha256:2bb80d…'
     * (new Redactor('omit'))->preview('secret');                          // null
     * ```
     *
     * @param  string|array<array-key, mixed>  $value  Text, or data that is JSON encoded first.
     * @return string|null The preview, or null when omitted.
     */
    public function preview(string|array $value): ?string
    {
        $text = is_string($value)
            ? $value
            : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return match ($this->strategy) {
            self::OMIT => null,
            self::HASH => 'sha256:'.hash('sha256', $text),
            self::TRUNCATE => mb_strlen($text) > $this->length ? mb_substr($text, 0, $this->length).'…' : $text,
            default => $text,
        };
    }

    /**
     * Remove the denied fields from a trace before it is sent.
     *
     * Example:
     * ```php
     * $redactor->trace(Trace::of(['ticket_id' => 42, 'email' => 'a@b.c'])); // ['ticket_id' => 42]
     * ```
     *
     * @param  Trace  $trace  The trace of the request.
     * @return Trace The trace without denied fields.
     */
    public function trace(Trace $trace): Trace
    {
        return $this->traceDenyKeys === [] ? $trace : $trace->without($this->traceDenyKeys);
    }
}
