<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Null;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Usage\RunRecord;
use Sysborg\LaravelJevai\Domain\Usage\UsageQuery;
use Sysborg\LaravelJevai\Domain\Usage\UsageSummary;
use Sysborg\LaravelJevai\Ports\Driven\UsageRepository;

/**
 * Usage repository used when persistence is disabled: stores nothing.
 */
final class NullUsageRepository implements UsageRepository
{
    /**
     * Discard the record.
     *
     * Example:
     * ```php
     * (new NullUsageRepository)->record($record); // no-op
     * ```
     *
     * @param  RunRecord  $record  Ignored.
     * @return void Nothing.
     */
    public function record(RunRecord $record): void {}

    /**
     * Nothing is stored, so there is nothing to summarize.
     *
     * Example:
     * ```php
     * (new NullUsageRepository)->summarize($query); // []
     * ```
     *
     * @param  UsageQuery  $query  Ignored.
     * @return list<UsageSummary> Always empty.
     */
    public function summarize(UsageQuery $query): array
    {
        return [];
    }

    /**
     * Nothing is stored, so nothing is pruned.
     *
     * Example:
     * ```php
     * (new NullUsageRepository)->prune(new DateTimeImmutable('-90 days')); // 0
     * ```
     *
     * @param  DateTimeImmutable  $before  Ignored.
     * @return int Always 0.
     */
    public function prune(DateTimeImmutable $before): int
    {
        return 0;
    }
}
