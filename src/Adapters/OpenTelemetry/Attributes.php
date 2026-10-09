<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\OpenTelemetry;

/**
 * Normalizes package attributes for OpenTelemetry: no null values, no empty keys.
 *
 * @internal
 */
final class Attributes
{
    /**
     * Keep only non-null values under non-empty keys.
     *
     * Example:
     * ```php
     * Attributes::of(['model' => 'clef', 'run_id' => null, '' => 1]); // ['model' => 'clef']
     * ```
     *
     * @param  array<string, string|int|float|bool|null>  $attributes  Name => value.
     * @return array<non-empty-string, string|int|float|bool> The usable attributes.
     */
    public static function of(array $attributes): array
    {
        $clean = [];

        foreach ($attributes as $key => $value) {
            if ($key !== '' && $value !== null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
