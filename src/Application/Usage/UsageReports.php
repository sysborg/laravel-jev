<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Usage;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Usage\UsageQuery;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\UsageRepository;

/**
 * Entry point of usage reports, resolved by the `JevUsage` facade.
 */
final readonly class UsageReports
{
    /**
     * Create the service.
     *
     * Example:
     * ```php
     * app(UsageReports::class)->lastDays(7)->byModel()->get();
     * ```
     *
     * @param  UsageRepository  $repository  Where records are stored.
     * @param  Clock  $clock  Defines "now" for relative periods.
     */
    public function __construct(
        private UsageRepository $repository,
        private Clock $clock,
    ) {}

    /**
     * Report on an explicit period.
     *
     * Example:
     * ```php
     * JevUsage::between(now()->startOfMonth(), now())->byDay()->get();
     * ```
     *
     * @param  DateTimeInterface  $from  Start, inclusive.
     * @param  DateTimeInterface  $to  End, exclusive.
     * @return UsageReport The report.
     *
     * @throws InvalidValue When `$from` is not before `$to`.
     */
    public function between(DateTimeInterface $from, DateTimeInterface $to): UsageReport
    {
        return new UsageReport(
            $this->repository,
            UsageQuery::between(DateTimeImmutable::createFromInterface($from), DateTimeImmutable::createFromInterface($to)),
        );
    }

    /**
     * Report from a moment until now.
     *
     * Example:
     * ```php
     * JevUsage::since(new DateTimeImmutable('2026-10-01'))->total();
     * ```
     *
     * @param  DateTimeInterface  $from  Start, inclusive.
     * @return UsageReport The report.
     *
     * @throws InvalidValue When `$from` is not in the past.
     */
    public function since(DateTimeInterface $from): UsageReport
    {
        return $this->between($from, $this->now());
    }

    /**
     * Report on the last N days, until now.
     *
     * Example:
     * ```php
     * JevUsage::lastDays(7)->byModel()->get();
     * ```
     *
     * @param  int  $days  Number of days, at least 1.
     * @return UsageReport The report.
     *
     * @throws InvalidValue When `$days` is lower than 1.
     */
    public function lastDays(int $days): UsageReport
    {
        return $this->last($days, 'D', 'days');
    }

    /**
     * Report on the last N hours, until now.
     *
     * Example:
     * ```php
     * JevUsage::lastHours(24)->total();
     * ```
     *
     * @param  int  $hours  Number of hours, at least 1.
     * @return UsageReport The report.
     *
     * @throws InvalidValue When `$hours` is lower than 1.
     */
    public function lastHours(int $hours): UsageReport
    {
        return $this->last($hours, 'H', 'hours');
    }

    /**
     * Report on the current UTC day, until now.
     *
     * Example:
     * ```php
     * JevUsage::today()->total()->inputTokensCharged;
     * ```
     *
     * @return UsageReport The report.
     */
    public function today(): UsageReport
    {
        $now = $this->now();
        $midnight = $now->setTime(0, 0);

        return $this->between($midnight, $midnight === $now ? $now->modify('+1 second') : $now);
    }

    /**
     * Report on a relative period such as `30m`, `24h`, `7d`, `2w`, or since a date such as `2026-10-01`.
     *
     * Example:
     * ```php
     * JevUsage::period('7d')->byModel()->get(); // what `php artisan jev:usage --since=7d` runs
     * ```
     *
     * @param  string  $period  The period.
     * @return UsageReport The report.
     *
     * @throws InvalidValue When the period cannot be understood or is not in the past.
     */
    public function period(string $period): UsageReport
    {
        $period = strtolower(trim($period));

        if (preg_match('/^(\d+)\s*([mhdw])$/', $period, $match) === 1) {
            $amount = (int) $match[1];

            if ($amount < 1) {
                throw InvalidValue::because('usage period', 'must be at least 1');
            }

            $interval = match ($match[2]) {
                'm' => "PT{$amount}M",
                'h' => "PT{$amount}H",
                'd' => "P{$amount}D",
                default => 'P'.($amount * 7).'D',
            };

            return $this->since($this->now()->sub(new DateInterval($interval)));
        }

        try {
            $from = new DateTimeImmutable($period, new DateTimeZone('UTC'));
        } catch (Exception) {
            throw InvalidValue::because('usage period', 'must look like 30m, 24h, 7d, 2w or a date');
        }

        return $this->since($from);
    }

    /**
     * Report on the last N units, until now.
     *
     * Example:
     * ```php
     * $this->last(7, 'D', 'days');
     * ```
     *
     * @param  int  $amount  Number of units, at least 1.
     * @param  string  $designator  ISO 8601 duration designator (D or H).
     * @param  string  $unit  Unit name, for error messages.
     * @return UsageReport The report.
     *
     * @throws InvalidValue When `$amount` is lower than 1.
     */
    private function last(int $amount, string $designator, string $unit): UsageReport
    {
        if ($amount < 1) {
            throw InvalidValue::because("usage period {$unit}", 'must be at least 1');
        }

        $prefix = $designator === 'H' ? 'PT' : 'P';

        return $this->since($this->now()->sub(new DateInterval("{$prefix}{$amount}{$designator}")));
    }

    /**
     * The current time in UTC.
     *
     * Example:
     * ```php
     * $this->now(); // 2026-10-09 12:00:00 UTC
     * ```
     *
     * @return DateTimeImmutable Now.
     */
    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }
}
