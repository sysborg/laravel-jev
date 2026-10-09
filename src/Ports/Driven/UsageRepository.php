<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Ports\Driven;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Usage\RunRecord;
use Sysborg\LaravelJevai\Domain\Usage\UsageQuery;
use Sysborg\LaravelJevai\Domain\Usage\UsageSummary;

/**
 * Stores finished calls and aggregates them for usage and cost reporting.
 *
 * @api
 */
interface UsageRepository
{
    /**
     * Store one finished call.
     *
     * Failures here must never break the Jev call: the application layer
     * catches and logs anything thrown.
     *
     * Example:
     * ```php
     * $repository->record(RunRecord::forDecision($request, $result, $clock->now()));
     * ```
     *
     * @param  RunRecord  $record  The call to store.
     * @return void Nothing.
     */
    public function record(RunRecord $record): void;

    /**
     * Aggregate stored calls.
     *
     * Example:
     * ```php
     * $repository->summarize(
     *     UsageQuery::between($from, $to)->groupBy(UsageGrouping::Model),
     * ); // [UsageSummary('jev-latest', ...), UsageSummary('clef', ...)]
     * ```
     *
     * @param  UsageQuery  $query  Period, filters and grouping.
     * @return list<UsageSummary> One summary per group, or a single one when ungrouped.
     */
    public function summarize(UsageQuery $query): array;

    /**
     * Delete calls that finished before a moment, for retention.
     *
     * Example:
     * ```php
     * $deleted = $repository->prune(new DateTimeImmutable('-90 days'));
     * ```
     *
     * @param  DateTimeImmutable  $before  Records older than this are deleted.
     * @return int Number of deleted records.
     */
    public function prune(DateTimeImmutable $before): int;
}
