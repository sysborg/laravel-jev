<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Console;

use Illuminate\Console\Command;
use Sysborg\LaravelJevai\Application\Usage\UsageReports;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;
use Sysborg\LaravelJevai\Domain\Usage\UsageGrouping;
use Sysborg\LaravelJevai\Domain\Usage\UsageSummary;

/**
 * `php artisan jev:usage`: tokens, credits, errors and latency of stored calls.
 */
final class UsageCommand extends Command
{
    /** @var string */
    protected $signature = 'jev:usage
        {--since=7d : Period: 30m, 24h, 7d, 2w, or a date such as 2026-10-01}
        {--by=model : Group by none, model, connection, operation, user, session or day}
        {--model= : Only this model}
        {--connection= : Only this connection}
        {--operation= : Only decision, judge or web_context}
        {--json : Print JSON instead of a table}';

    /** @var string */
    protected $description = 'Show Jev token usage, charges, errors and latency';

    /**
     * Print the report.
     *
     * Example:
     * ```bash
     * php artisan jev:usage --since=30d --by=day
     * ```
     *
     * @param  UsageReports  $reports  The usage report service.
     * @return int Exit code: 0 on success, 1 on invalid options.
     */
    public function handle(UsageReports $reports): int
    {
        try {
            $grouping = UsageGrouping::tryFrom($this->stringOption('by') ?? 'model')
                ?? throw InvalidValue::because('--by', 'must be none, model, connection, operation, user, session or day');
            $operation = $this->stringOption('operation');

            $summaries = $reports->period($this->stringOption('since') ?? '7d')
                ->groupBy($grouping)
                ->whereModel($this->stringOption('model'))
                ->whereConnection($this->stringOption('connection'))
                ->whereOperation($operation === null ? null : (RunOperation::tryFrom($operation)
                    ?? throw InvalidValue::because('--operation', 'must be decision, judge or web_context')))
                ->get();
        } catch (InvalidValue $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line((string) json_encode(array_map($this->row(...), $summaries), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Group', 'Calls', 'Failures', 'Error %', 'Input tokens', 'Output tokens', 'Tokens charged', 'Credits', 'Avg latency (ms)', 'Uncertain billing'],
            array_map(fn (UsageSummary $summary): array => array_values($this->row($summary)), $summaries),
        );

        return self::SUCCESS;
    }

    /**
     * One summary as a printable row.
     *
     * Example:
     * ```php
     * $this->row($summary); // ['group' => 'clef', 'calls' => 12, ...]
     * ```
     *
     * @param  UsageSummary  $summary  The summary.
     * @return array<string, string|int|float> Column => value.
     */
    private function row(UsageSummary $summary): array
    {
        return [
            'group' => $summary->group ?? 'total',
            'calls' => $summary->calls,
            'failures' => $summary->failures,
            'error_rate' => round($summary->errorRate() * 100, 2),
            'input_tokens' => $summary->inputTokens,
            'output_tokens' => $summary->outputTokens,
            'input_tokens_charged' => $summary->inputTokensCharged,
            'credits_charged' => $summary->creditsCharged,
            'average_latency_ms' => round($summary->averageLatencyMs(), 1),
            'uncertain_billings' => $summary->uncertainBillings,
        ];
    }

    /**
     * Read a string option; empty means not given.
     *
     * Example:
     * ```php
     * $this->stringOption('model'); // 'clef' or null
     * ```
     *
     * @param  string  $name  Option name.
     * @return string|null The value, or null.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
