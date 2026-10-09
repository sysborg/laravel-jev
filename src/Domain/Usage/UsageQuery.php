<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Usage;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;

/**
 * Which usage records to summarize and how to group them.
 *
 * @api
 */
final readonly class UsageQuery
{
    /**
     * Create the query. Use {@see between()} and the `where*()` / {@see groupBy()} methods.
     *
     * Example:
     * ```php
     * UsageQuery::between($from, $to)->groupBy(UsageGrouping::Model);
     * ```
     *
     * @param  DateTimeImmutable  $from  Start of the period, inclusive.
     * @param  DateTimeImmutable  $to  End of the period, exclusive.
     * @param  UsageGrouping  $grouping  How to group the summaries.
     * @param  string|null  $model  Only this model, when set.
     * @param  string|null  $connection  Only this connection, when set.
     * @param  RunOperation|null  $operation  Only this operation, when set.
     * @param  string|null  $user  Only this user label, when set.
     * @param  string|null  $sessionId  Only this session label, when set.
     *
     * @throws InvalidValue When `$from` is not before `$to`.
     */
    private function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public UsageGrouping $grouping,
        public ?string $model,
        public ?string $connection,
        public ?RunOperation $operation,
        public ?string $user,
        public ?string $sessionId,
    ) {
        if ($from >= $to) {
            throw InvalidValue::because('usage query period', 'must start before it ends');
        }
    }

    /**
     * Query a period, as a single total.
     *
     * Example:
     * ```php
     * UsageQuery::between(new DateTimeImmutable('-7 days'), new DateTimeImmutable);
     * ```
     *
     * @param  DateTimeImmutable  $from  Start of the period, inclusive.
     * @param  DateTimeImmutable  $to  End of the period, exclusive.
     * @return self The query, ungrouped and unfiltered.
     *
     * @throws InvalidValue When `$from` is not before `$to`.
     */
    public static function between(DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        return new self($from, $to, UsageGrouping::None, null, null, null, null, null);
    }

    /**
     * Return a copy grouped differently.
     *
     * Example:
     * ```php
     * $query = $query->groupBy(UsageGrouping::Day);
     * ```
     *
     * @param  UsageGrouping  $grouping  How to group the summaries.
     * @return self A new query; the original is unchanged.
     */
    public function groupBy(UsageGrouping $grouping): self
    {
        return new self($this->from, $this->to, $grouping, $this->model, $this->connection, $this->operation, $this->user, $this->sessionId);
    }

    /**
     * Return a copy limited to one model.
     *
     * Example:
     * ```php
     * $query = $query->whereModel('clef');
     * ```
     *
     * @param  string|null  $model  Model name, or null for all models.
     * @return self A new query; the original is unchanged.
     */
    public function whereModel(?string $model): self
    {
        return new self($this->from, $this->to, $this->grouping, $model, $this->connection, $this->operation, $this->user, $this->sessionId);
    }

    /**
     * Return a copy limited to one connection.
     *
     * Example:
     * ```php
     * $query = $query->whereConnection('tenant-a');
     * ```
     *
     * @param  string|null  $connection  Connection name, or null for all connections.
     * @return self A new query; the original is unchanged.
     */
    public function whereConnection(?string $connection): self
    {
        return new self($this->from, $this->to, $this->grouping, $this->model, $connection, $this->operation, $this->user, $this->sessionId);
    }

    /**
     * Return a copy limited to one operation.
     *
     * Example:
     * ```php
     * $query = $query->whereOperation(RunOperation::WebContext);
     * ```
     *
     * @param  RunOperation|null  $operation  Operation, or null for all operations.
     * @return self A new query; the original is unchanged.
     */
    public function whereOperation(?RunOperation $operation): self
    {
        return new self($this->from, $this->to, $this->grouping, $this->model, $this->connection, $operation, $this->user, $this->sessionId);
    }

    /**
     * Return a copy limited to one user label.
     *
     * Example:
     * ```php
     * $query = $query->whereUser((string) $user->id);
     * ```
     *
     * @param  string|null  $user  User label, or null for all users.
     * @return self A new query; the original is unchanged.
     */
    public function whereUser(?string $user): self
    {
        return new self($this->from, $this->to, $this->grouping, $this->model, $this->connection, $this->operation, $user, $this->sessionId);
    }

    /**
     * Return a copy limited to one session label.
     *
     * Example:
     * ```php
     * $query = $query->whereSession('ticket-42');
     * ```
     *
     * @param  string|null  $sessionId  Session label, or null for all sessions.
     * @return self A new query; the original is unchanged.
     */
    public function whereSession(?string $sessionId): self
    {
        return new self($this->from, $this->to, $this->grouping, $this->model, $this->connection, $this->operation, $this->user, $sessionId);
    }
}
