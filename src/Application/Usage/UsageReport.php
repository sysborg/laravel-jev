<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Usage;

use Sysborg\LaravelJevai\Domain\Usage\RunOperation;
use Sysborg\LaravelJevai\Domain\Usage\UsageGrouping;
use Sysborg\LaravelJevai\Domain\Usage\UsageQuery;
use Sysborg\LaravelJevai\Domain\Usage\UsageSummary;
use Sysborg\LaravelJevai\Ports\Driven\UsageRepository;

/**
 * Immutable fluent report over stored usage.
 *
 * ```php
 * JevUsage::lastDays(7)->byModel()->get();     // one UsageSummary per model
 * JevUsage::today()->whereUser('42')->total(); // a single UsageSummary
 * ```
 */
final readonly class UsageReport
{
    /**
     * Create the report.
     *
     * Example:
     * ```php
     * new UsageReport($repository, UsageQuery::between($from, $to));
     * ```
     *
     * @param  UsageRepository  $repository  Where records are stored.
     * @param  UsageQuery  $query  Period, filters and grouping.
     */
    public function __construct(
        private UsageRepository $repository,
        private UsageQuery $query,
    ) {}

    /**
     * Group by model.
     *
     * Example:
     * ```php
     * $report->byModel()->get(); // [UsageSummary('clef', ...), UsageSummary('jev-latest', ...)]
     * ```
     *
     * @return self A new report.
     */
    public function byModel(): self
    {
        return $this->groupBy(UsageGrouping::Model);
    }

    /**
     * Group by package connection.
     *
     * Example:
     * ```php
     * $report->byConnection()->get();
     * ```
     *
     * @return self A new report.
     */
    public function byConnection(): self
    {
        return $this->groupBy(UsageGrouping::Connection);
    }

    /**
     * Group by operation (decision, judge, web_context).
     *
     * Example:
     * ```php
     * $report->byOperation()->get();
     * ```
     *
     * @return self A new report.
     */
    public function byOperation(): self
    {
        return $this->groupBy(UsageGrouping::Operation);
    }

    /**
     * Group by Jev `user` label.
     *
     * Example:
     * ```php
     * $report->byUser()->get();
     * ```
     *
     * @return self A new report.
     */
    public function byUser(): self
    {
        return $this->groupBy(UsageGrouping::User);
    }

    /**
     * Group by Jev `session_id` label.
     *
     * Example:
     * ```php
     * $report->bySession()->get();
     * ```
     *
     * @return self A new report.
     */
    public function bySession(): self
    {
        return $this->groupBy(UsageGrouping::Session);
    }

    /**
     * Group by calendar day (UTC), keyed `Y-m-d`.
     *
     * Example:
     * ```php
     * $report->byDay()->get(); // [UsageSummary('2026-10-08', ...), UsageSummary('2026-10-09', ...)]
     * ```
     *
     * @return self A new report.
     */
    public function byDay(): self
    {
        return $this->groupBy(UsageGrouping::Day);
    }

    /**
     * Group by any {@see UsageGrouping}.
     *
     * Example:
     * ```php
     * $report->groupBy(UsageGrouping::from($request->query('by', 'none')));
     * ```
     *
     * @param  UsageGrouping  $grouping  The grouping.
     * @return self A new report.
     */
    public function groupBy(UsageGrouping $grouping): self
    {
        return new self($this->repository, $this->query->groupBy($grouping));
    }

    /**
     * Only one model.
     *
     * Example:
     * ```php
     * $report->whereModel('clef');
     * ```
     *
     * @param  string|null  $model  Model name, or null for all.
     * @return self A new report.
     */
    public function whereModel(?string $model): self
    {
        return new self($this->repository, $this->query->whereModel($model));
    }

    /**
     * Only one connection.
     *
     * Example:
     * ```php
     * $report->whereConnection('tenant-a');
     * ```
     *
     * @param  string|null  $connection  Connection name, or null for all.
     * @return self A new report.
     */
    public function whereConnection(?string $connection): self
    {
        return new self($this->repository, $this->query->whereConnection($connection));
    }

    /**
     * Only one operation.
     *
     * Example:
     * ```php
     * $report->whereOperation(RunOperation::WebContext);
     * ```
     *
     * @param  RunOperation|null  $operation  Operation, or null for all.
     * @return self A new report.
     */
    public function whereOperation(?RunOperation $operation): self
    {
        return new self($this->repository, $this->query->whereOperation($operation));
    }

    /**
     * Only one `user` label.
     *
     * Example:
     * ```php
     * $report->whereUser((string) $user->id);
     * ```
     *
     * @param  string|null  $user  User label, or null for all.
     * @return self A new report.
     */
    public function whereUser(?string $user): self
    {
        return new self($this->repository, $this->query->whereUser($user));
    }

    /**
     * Only one `session_id` label.
     *
     * Example:
     * ```php
     * $report->whereSession('ticket-42');
     * ```
     *
     * @param  string|null  $sessionId  Session label, or null for all.
     * @return self A new report.
     */
    public function whereSession(?string $sessionId): self
    {
        return new self($this->repository, $this->query->whereSession($sessionId));
    }

    /**
     * Run the report.
     *
     * Example:
     * ```php
     * foreach (JevUsage::lastDays(30)->byModel()->get() as $summary) {
     *     echo "{$summary->group}: {$summary->inputTokensCharged} tokens";
     * }
     * ```
     *
     * @return list<UsageSummary> One summary per group, or one total when ungrouped.
     */
    public function get(): array
    {
        return $this->repository->summarize($this->query);
    }

    /**
     * Run the report as a single total, ignoring any grouping.
     *
     * Example:
     * ```php
     * JevUsage::today()->total()->creditsCharged; // 3.0
     * ```
     *
     * @return UsageSummary The total of the period and filters.
     */
    public function total(): UsageSummary
    {
        return $this->repository->summarize($this->query->groupBy(UsageGrouping::None))[0]
            ?? new UsageSummary(null, 0, 0, 0, 0, 0, 0.0, 0);
    }

    /**
     * The underlying query.
     *
     * Example:
     * ```php
     * $report->query()->from; // DateTimeImmutable
     * ```
     *
     * @return UsageQuery The query.
     */
    public function query(): UsageQuery
    {
        return $this->query;
    }
}
