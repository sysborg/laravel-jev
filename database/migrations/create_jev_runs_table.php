<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the usage table (`jev.usage.table`) on the usage connection (`jev.usage.connection`).
     *
     * Example:
     * ```bash
     * php artisan vendor:publish --tag=jev-migrations && php artisan migrate
     * ```
     *
     * @return void Nothing.
     */
    public function up(): void
    {
        Schema::connection($this->usageConnection())->create($this->usageTable(), function (Blueprint $table): void {
            $table->id();
            $table->string('correlation_id', 128)->unique();
            $table->string('operation', 20);
            $table->string('status', 16);
            $table->string('model', 128)->nullable()->index();
            $table->string('connection', 64)->nullable()->index();
            $table->string('run_id', 128)->nullable();
            $table->string('judge_id', 256)->nullable();
            $table->unsignedInteger('judge_revision')->nullable();
            $table->string('session_id', 256)->nullable()->index();
            $table->string('user_label', 256)->nullable()->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 128)->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('input_tokens_charged')->default(0);
            $table->decimal('credits_charged', 18, 6)->default(0);
            $table->string('billing_mode', 32);
            $table->boolean('billing_uncertain')->default(false);
            $table->unsignedInteger('latency_ms');
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->json('context')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->index();
        });
    }

    /**
     * Drop the usage table.
     *
     * Example:
     * ```bash
     * php artisan migrate:rollback
     * ```
     *
     * @return void Nothing.
     */
    public function down(): void
    {
        Schema::connection($this->usageConnection())->dropIfExists($this->usageTable());
    }

    /**
     * The configured usage DB connection.
     *
     * Example:
     * ```php
     * $this->usageConnection(); // null (default connection) or 'analytics'
     * ```
     *
     * @return string|null The connection name, or null for the default.
     */
    private function usageConnection(): ?string
    {
        $connection = config('jev.usage.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * The configured usage table.
     *
     * Example:
     * ```php
     * $this->usageTable(); // 'jev_runs'
     * ```
     *
     * @return string The table name.
     */
    private function usageTable(): string
    {
        $table = config('jev.usage.table');

        return is_string($table) && $table !== '' ? $table : 'jev_runs';
    }
};
