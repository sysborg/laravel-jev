<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One stored Jev call (`jev_runs`), for querying usage with Eloquent.
 *
 * The package writes through {@see EloquentUsageRepository}; this model is for
 * reading, e.g. `JevRun::where('model', 'clef')->sum('input_tokens_charged')`.
 *
 * @property int $id
 * @property string $correlation_id
 * @property string $operation
 * @property string $status
 * @property string|null $model
 * @property string|null $connection
 * @property string|null $run_id
 * @property string|null $judge_id
 * @property int|null $judge_revision
 * @property string|null $session_id
 * @property string|null $user_label
 * @property int|null $http_status
 * @property string|null $error_code
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $input_tokens_charged
 * @property string $credits_charged
 * @property string $billing_mode
 * @property bool $billing_uncertain
 * @property int $latency_ms
 * @property int $attempts
 * @property array<string, mixed>|null $context
 * @property array<string, mixed>|null $payload
 * @property Carbon $occurred_at
 */
final class JevRun extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Use the configured usage table and connection.
     *
     * Example:
     * ```php
     * (new JevRun)->getTable(); // 'jev_runs'
     * ```
     *
     * @param  array<string, mixed>  $attributes  Initial attributes.
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $table = config('jev.usage.table');
        $connection = config('jev.usage.connection');

        $this->setTable(is_string($table) && $table !== '' ? $table : 'jev_runs');

        if (is_string($connection) && $connection !== '') {
            $this->setConnection($connection);
        }
    }

    /**
     * Attribute casts.
     *
     * Example:
     * ```php
     * JevRun::first()->context; // ['ticket_id' => 42]
     * ```
     *
     * @return array<string, string> Attribute => cast.
     */
    protected function casts(): array
    {
        return [
            'judge_revision' => 'integer',
            'http_status' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'input_tokens_charged' => 'integer',
            'credits_charged' => 'decimal:6',
            'billing_uncertain' => 'boolean',
            'latency_ms' => 'integer',
            'attempts' => 'integer',
            'context' => 'array',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Records older than `jev.usage.retention_days`, for `php artisan model:prune`.
     *
     * Example:
     * ```bash
     * php artisan model:prune --model="Sysborg\LaravelJevai\Adapters\Database\JevRun"
     * ```
     *
     * @return Builder<self> The records to delete.
     */
    public function prunable(): Builder
    {
        $days = config('jev.usage.retention_days');

        return self::query()->where('occurred_at', '<', now()->subDays(is_numeric($days) ? (int) $days : 90));
    }
}
