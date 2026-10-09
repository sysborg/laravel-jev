<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;

/**
 * Resolves configured connections by name, validating each once.
 */
final class ConnectionRegistry
{
    /** @var array<string, JevConnection> */
    private array $resolved = [];

    /**
     * Create the registry.
     *
     * Example:
     * ```php
     * new ConnectionRegistry(config('jev.connections'), config('jev.default'), app()->environment('testing'));
     * ```
     *
     * @param  array<array-key, mixed>  $connections  `jev.connections`: name => settings.
     * @param  string  $default  Name of the default connection.
     * @param  bool  $allowInsecure  Whether `http://` base URLs are accepted (testing only).
     */
    public function __construct(
        private readonly array $connections,
        private readonly string $default,
        private readonly bool $allowInsecure = false,
    ) {}

    /**
     * Get a connection.
     *
     * Example:
     * ```php
     * $registry->get();           // the default connection
     * $registry->get('tenant-a'); // a named connection
     * ```
     *
     * @param  string|null  $name  Connection name, or null for the default.
     * @return JevConnection The validated connection.
     *
     * @throws InvalidValue When the connection is not configured or its settings are invalid.
     */
    public function get(?string $name = null): JevConnection
    {
        $name ??= $this->default;

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $config = $this->connections[$name] ?? null;

        if (! is_array($config)) {
            throw InvalidValue::because("Jev connection [{$name}]", 'is not configured under jev.connections');
        }

        return $this->resolved[$name] = JevConnection::fromConfig($name, $config, $this->allowInsecure);
    }

    /**
     * Name of the default connection.
     *
     * Example:
     * ```php
     * $registry->defaultName(); // 'default'
     * ```
     *
     * @return string The name.
     */
    public function defaultName(): string
    {
        return $this->default;
    }

    /**
     * Names of all configured connections.
     *
     * Example:
     * ```php
     * $registry->names(); // ['default', 'tenant-a']
     * ```
     *
     * @return list<string> The names.
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->connections));
    }
}
