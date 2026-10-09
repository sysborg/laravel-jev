<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Database;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Sysborg\LaravelJevai\Domain\Usage\RunRecord;
use Sysborg\LaravelJevai\Domain\Usage\RunStatus;
use Sysborg\LaravelJevai\Domain\Usage\UsageGrouping;
use Sysborg\LaravelJevai\Domain\Usage\UsageQuery;
use Sysborg\LaravelJevai\Domain\Usage\UsageSummary;
use Sysborg\LaravelJevai\Ports\Driven\UsageRepository;

/**
 * {@see UsageRepository} on a database table (`jev_runs` by default).
 *
 * Uses the query builder rather than Eloquent models for cheap inserts and
 * portable aggregates (MySQL/MariaDB, PostgreSQL, SQLite, SQL Server).
 */
final readonly class EloquentUsageRepository implements UsageRepository
{
    private const string DATE_FORMAT = 'Y-m-d H:i:s';

    /** Aggregate columns; bindings: failed status, then `true` for uncertain billing. */
    private const string AGGREGATES = 'COUNT(*) as calls, '
        .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failures, '
        .'COALESCE(SUM(input_tokens), 0) as input_tokens, '
        .'COALESCE(SUM(output_tokens), 0) as output_tokens, '
        .'COALESCE(SUM(input_tokens_charged), 0) as input_tokens_charged, '
        .'COALESCE(SUM(credits_charged), 0) as credits_charged, '
        .'COALESCE(SUM(latency_ms), 0) as latency_ms, '
        .'SUM(CASE WHEN billing_uncertain = ? THEN 1 ELSE 0 END) as uncertain';

    /**
     * Create the repository.
     *
     * Example:
     * ```php
     * new EloquentUsageRepository(app('db'), connection: null, table: 'jev_runs');
     * ```
     *
     * @param  ConnectionResolverInterface  $db  Laravel's database manager.
     * @param  string|null  $connection  DB connection name, or null for the default.
     * @param  string  $table  Table name.
     */
    public function __construct(
        private ConnectionResolverInterface $db,
        private ?string $connection = null,
        private string $table = 'jev_runs',
    ) {}

    /**
     * Insert one record. Times are stored in UTC.
     *
     * Example:
     * ```php
     * $repository->record(RunRecord::forDecision($request, $result, $clock->now()));
     * ```
     *
     * @param  RunRecord  $record  The finished call.
     * @return void Nothing.
     */
    public function record(RunRecord $record): void
    {
        $this->query()->insert([
            'correlation_id' => (string) $record->correlationId,
            'operation' => $record->operation->value,
            'status' => $record->status->value,
            'model' => $record->model,
            'connection' => $record->connection,
            'run_id' => $record->runId,
            'judge_id' => $record->judgeId,
            'judge_revision' => $record->judgeRevision,
            'session_id' => $record->sessionId,
            'user_label' => $record->user,
            'http_status' => $record->httpStatus,
            'error_code' => $record->errorCode,
            'input_tokens' => $record->usage->inputTokens,
            'output_tokens' => $record->usage->outputTokens,
            'input_tokens_charged' => $record->billing->inputTokensCharged,
            'credits_charged' => $record->billing->creditsCharged,
            'billing_mode' => $record->billing->mode->value,
            'billing_uncertain' => $record->billingUncertain,
            'latency_ms' => $record->latencyMs,
            'attempts' => $record->attempts,
            'context' => $record->context->isEmpty() ? null : json_encode($record->context->toArray(), JSON_THROW_ON_ERROR),
            'payload' => $record->payload === null ? null : json_encode($record->payload, JSON_PARTIAL_OUTPUT_ON_ERROR),
            'occurred_at' => $this->utc($record->occurredAt),
        ]);
    }

    /**
     * Aggregate the records of a period.
     *
     * Example:
     * ```php
     * $repository->summarize(UsageQuery::between($from, $to)->groupBy(UsageGrouping::Model));
     * ```
     *
     * @param  UsageQuery  $query  Period, filters and grouping.
     * @return list<UsageSummary> One summary per group (ordered by group), or one total when ungrouped.
     */
    public function summarize(UsageQuery $query): array
    {
        $builder = $this->query()
            ->where('occurred_at', '>=', $this->utc($query->from))
            ->where('occurred_at', '<', $this->utc($query->to));

        foreach ([
            'model' => $query->model,
            'connection' => $query->connection,
            'operation' => $query->operation?->value,
            'user_label' => $query->user,
            'session_id' => $query->sessionId,
        ] as $column => $value) {
            if ($value !== null) {
                $builder->where($column, $value);
            }
        }

        $group = $this->groupExpression($query->grouping);

        $builder->selectRaw(
            ($group ?? 'NULL').' as group_key, '.self::AGGREGATES,
            [RunStatus::Failed->value, true],
        );

        if ($group !== null) {
            $builder->groupByRaw($group)->orderByRaw($group);
        }

        $summaries = [];

        foreach ($builder->get() as $row) {
            $row = (array) $row;
            $groupKey = $row['group_key'] ?? null;

            $summaries[] = new UsageSummary(
                group: is_scalar($groupKey) ? (string) $groupKey : null,
                calls: self::int($row['calls'] ?? 0),
                failures: self::int($row['failures'] ?? 0),
                inputTokens: self::int($row['input_tokens'] ?? 0),
                outputTokens: self::int($row['output_tokens'] ?? 0),
                inputTokensCharged: self::int($row['input_tokens_charged'] ?? 0),
                creditsCharged: round(is_numeric($row['credits_charged'] ?? null) ? (float) $row['credits_charged'] : 0.0, 6),
                totalLatencyMs: self::int($row['latency_ms'] ?? 0),
                uncertainBillings: self::int($row['uncertain'] ?? 0),
            );
        }

        return $summaries;
    }

    /**
     * Delete records that finished before a moment.
     *
     * Example:
     * ```php
     * $repository->prune(new DateTimeImmutable('-90 days')); // 1234
     * ```
     *
     * @param  DateTimeImmutable  $before  Records older than this are deleted.
     * @return int Number of deleted records.
     */
    public function prune(DateTimeImmutable $before): int
    {
        return $this->query()->where('occurred_at', '<', $this->utc($before))->delete();
    }

    /**
     * SQL expression of a grouping, portable across the supported drivers.
     *
     * Example:
     * ```php
     * $this->groupExpression(UsageGrouping::Day); // "DATE(occurred_at)" on MySQL, PostgreSQL and SQLite
     * ```
     *
     * @param  UsageGrouping  $grouping  The grouping.
     * @return literal-string|null The column or expression, or null when ungrouped.
     */
    private function groupExpression(UsageGrouping $grouping): ?string
    {
        return match ($grouping) {
            UsageGrouping::None => null,
            UsageGrouping::Model => 'model',
            UsageGrouping::Connection => 'connection',
            UsageGrouping::Operation => 'operation',
            UsageGrouping::User => 'user_label',
            UsageGrouping::Session => 'session_id',
            UsageGrouping::Day => $this->driver() === 'sqlsrv'
                ? 'CAST(occurred_at AS date)'
                : 'DATE(occurred_at)',
        };
    }

    /**
     * Name of the database driver (mysql, pgsql, sqlite, sqlsrv...).
     *
     * Example:
     * ```php
     * $this->driver(); // 'pgsql'
     * ```
     *
     * @return string The driver, or '' when unknown.
     */
    private function driver(): string
    {
        $connection = $this->connection();

        return $connection instanceof Connection ? $connection->getDriverName() : '';
    }

    /**
     * Read an aggregate that drivers may return as int, float, numeric string or null.
     *
     * Example:
     * ```php
     * self::int('42'); // 42
     * self::int(null); // 0
     * ```
     *
     * @param  mixed  $value  The aggregate.
     * @return int The integer value.
     */
    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Format a moment in UTC for storage and comparison.
     *
     * Example:
     * ```php
     * $this->utc(new DateTimeImmutable('2026-10-09 09:00:00-03:00')); // '2026-10-09 12:00:00'
     * ```
     *
     * @param  DateTimeInterface  $moment  The moment.
     * @return string The UTC timestamp.
     */
    private function utc(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::DATE_FORMAT);
    }

    /**
     * A query on the usage table.
     *
     * Example:
     * ```php
     * $this->query()->count();
     * ```
     *
     * @return Builder The query.
     */
    private function query(): Builder
    {
        return $this->connection()->table($this->table);
    }

    /**
     * The usage DB connection.
     *
     * Example:
     * ```php
     * $this->connection()->getDriverName(); // 'mysql'
     * ```
     *
     * @return ConnectionInterface The connection.
     */
    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }
}
