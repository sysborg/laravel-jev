<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Facades;

use Illuminate\Support\Facades\Facade;
use Sysborg\LaravelJevai\Application\Usage\UsageReports;

/**
 * Static access to usage reports (requires `jev.usage.driver = database`).
 *
 * @method static \Sysborg\LaravelJevai\Application\Usage\UsageReport between(\DateTimeInterface $from, \DateTimeInterface $to)
 * @method static \Sysborg\LaravelJevai\Application\Usage\UsageReport since(\DateTimeInterface $from)
 * @method static \Sysborg\LaravelJevai\Application\Usage\UsageReport lastDays(int $days)
 * @method static \Sysborg\LaravelJevai\Application\Usage\UsageReport lastHours(int $hours)
 * @method static \Sysborg\LaravelJevai\Application\Usage\UsageReport today()
 * @method static \Sysborg\LaravelJevai\Application\Usage\UsageReport period(string $period)
 *
 * @see UsageReports
 *
 * @api
 */
final class JevUsage extends Facade
{
    /**
     * The container binding the facade resolves.
     *
     * Example:
     * ```php
     * JevUsage::getFacadeRoot(); // UsageReports
     * ```
     *
     * @return string The service class.
     */
    protected static function getFacadeAccessor(): string
    {
        return UsageReports::class;
    }
}
