<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;

/**
 * Settings of one configured Jev connection (`jev.connections.{name}`).
 */
final readonly class JevConnection
{
    public const string DEFAULT_BASE_URL = 'https://jev-ai.pro/api';

    public const string DEFAULT_MODEL = 'jev-latest';

    /**
     * Create the connection.
     *
     * Example:
     * ```php
     * new JevConnection('default', new ApiKey('sk-...'), 'https://jev-ai.pro/api', 'jev-latest', 5, 30);
     * ```
     *
     * @param  string  $name  Connection name.
     * @param  ApiKey  $apiKey  The connection's API key.
     * @param  string  $baseUrl  API base URL without trailing slash, e.g. `https://jev-ai.pro/api`.
     * @param  string  $model  Model used when a request does not name one.
     * @param  int  $connectTimeout  Seconds to wait for the TCP/TLS connection.
     * @param  int  $requestTimeout  Seconds to wait for the whole response.
     *
     * @throws InvalidValue When a timeout is lower than 1 second.
     */
    public function __construct(
        public string $name,
        public ApiKey $apiKey,
        public string $baseUrl,
        public string $model,
        public int $connectTimeout,
        public int $requestTimeout,
    ) {
        if ($connectTimeout < 1 || $requestTimeout < 1) {
            throw InvalidValue::because("Jev connection [{$name}] timeouts", 'must be at least 1 second');
        }
    }

    /**
     * Build a connection from its config array.
     *
     * Example:
     * ```php
     * JevConnection::fromConfig('default', config('jev.connections.default'), allowInsecure: false);
     * ```
     *
     * @param  string  $name  Connection name.
     * @param  array<array-key, mixed>  $config  `api_key` (string or {@see ApiKey}), `base_url`, `model` and `timeout.connect|request`.
     * @param  bool  $allowInsecure  Whether a plain `http://` base URL is accepted (testing only).
     * @return self The connection.
     *
     * @throws InvalidValue When the API key is missing, the base URL is not a valid https URL
     *                      (or http when allowed), or a value has the wrong type.
     */
    public static function fromConfig(string $name, array $config, bool $allowInsecure = false): self
    {
        $apiKey = $config['api_key'] ?? null;

        if (is_string($apiKey) && trim($apiKey) !== '') {
            $apiKey = new ApiKey($apiKey);
        }

        if (! $apiKey instanceof ApiKey) {
            throw InvalidValue::because(
                "Jev connection [{$name}] api_key",
                'must be set (JEV_AI_API_KEY for the default connection)',
            );
        }

        $timeout = is_array($config['timeout'] ?? null) ? $config['timeout'] : [];

        return new self(
            name: $name,
            apiKey: $apiKey,
            baseUrl: self::baseUrl($name, $config['base_url'] ?? self::DEFAULT_BASE_URL, $allowInsecure),
            model: self::string($name, 'model', $config['model'] ?? self::DEFAULT_MODEL),
            connectTimeout: self::int($name, 'timeout.connect', $timeout['connect'] ?? 5),
            requestTimeout: self::int($name, 'timeout.request', $timeout['request'] ?? 30),
        );
    }

    /**
     * Validate and normalize the base URL.
     *
     * Example:
     * ```php
     * self::baseUrl('default', 'https://jev-ai.pro/api/', false); // 'https://jev-ai.pro/api'
     * ```
     *
     * @param  string  $name  Connection name, for error messages.
     * @param  mixed  $value  Configured base URL.
     * @param  bool  $allowInsecure  Whether `http://` is accepted.
     * @return string The URL without trailing slash.
     *
     * @throws InvalidValue When the value is not a URL or uses a forbidden scheme.
     */
    private static function baseUrl(string $name, mixed $value, bool $allowInsecure): string
    {
        $url = is_string($value) ? rtrim(trim($value), '/') : '';
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw InvalidValue::because("Jev connection [{$name}] base_url", 'must be a valid URL');
        }

        if ($scheme !== 'https' && ! ($allowInsecure && $scheme === 'http')) {
            throw InvalidValue::because(
                "Jev connection [{$name}] base_url",
                'must use https:// (http:// is only allowed in the testing environment)',
            );
        }

        return $url;
    }

    /**
     * Read a non-blank string setting.
     *
     * Example:
     * ```php
     * self::string('default', 'model', 'jev-latest'); // 'jev-latest'
     * ```
     *
     * @param  string  $name  Connection name, for error messages.
     * @param  string  $key  Setting name, for error messages.
     * @param  mixed  $value  Configured value.
     * @return string The value.
     *
     * @throws InvalidValue When the value is not a non-blank string.
     */
    private static function string(string $name, string $key, mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw InvalidValue::because("Jev connection [{$name}] {$key}", 'must be a non-blank string');
        }

        return $value;
    }

    /**
     * Read an integer setting, accepting numeric strings from env().
     *
     * Example:
     * ```php
     * self::int('default', 'timeout.request', '30'); // 30
     * ```
     *
     * @param  string  $name  Connection name, for error messages.
     * @param  string  $key  Setting name, for error messages.
     * @param  mixed  $value  Configured value.
     * @return int The value.
     *
     * @throws InvalidValue When the value is not an integer.
     */
    private static function int(string $name, string $key, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw InvalidValue::because("Jev connection [{$name}] {$key}", 'must be an integer');
    }
}
