<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Tests\PulseTestCase;
use Sysborg\LaravelJevai\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Live');
pest()->extend(PulseTestCase::class)->in('Pulse');

/**
 * Load a JSON fixture from tests/Fixtures/jev.
 *
 * Example:
 * ```php
 * Http::fake(['*' => Http::response(jevFixture('decision-success'))]);
 * ```
 *
 * @param  string  $name  File name without the `.json` extension.
 * @return array<array-key, mixed> The decoded fixture.
 */
function jevFixture(string $name): array
{
    $decoded = json_decode((string) file_get_contents(__DIR__."/Fixtures/jev/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}
