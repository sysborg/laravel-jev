<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Console;

use DateInterval;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\UsageRepository;

/**
 * `php artisan jev:prune`: delete usage records older than the retention period.
 */
final class PruneCommand extends Command
{
    /** @var string */
    protected $signature = 'jev:prune {--days= : Keep this many days (default: jev.usage.retention_days)}';

    /** @var string */
    protected $description = 'Delete Jev usage records older than the retention period';

    /**
     * Delete old records.
     *
     * Example:
     * ```bash
     * php artisan jev:prune --days=30
     * ```
     *
     * Schedule it daily: `Schedule::command('jev:prune')->daily();`
     *
     * @param  UsageRepository  $repository  Where records are stored.
     * @param  Clock  $clock  Defines "now".
     * @param  Config  $config  Laravel's config, for the default retention.
     * @return int Exit code: 0 on success, 1 on an invalid `--days`.
     */
    public function handle(UsageRepository $repository, Clock $clock, Config $config): int
    {
        $option = $this->option('days');
        $default = $config->get('jev.usage.retention_days', 90);
        $days = is_string($option) && $option !== '' ? $option : (is_numeric($default) ? (string) $default : '90');

        if (preg_match('/^\d+$/', $days) !== 1 || (int) $days < 1) {
            $this->components->error('--days must be a positive integer.');

            return self::FAILURE;
        }

        $deleted = $repository->prune($clock->now()->sub(new DateInterval("P{$days}D")));

        $this->components->info("Deleted {$deleted} Jev usage record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
