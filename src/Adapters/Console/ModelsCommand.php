<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Console;

use Illuminate\Console\Command;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Ports\Driving\Jev;

/**
 * `php artisan jev:models`: models available to the account (free, no inference).
 */
final class ModelsCommand extends Command
{
    /** @var string */
    protected $signature = 'jev:models {--json : Print JSON instead of a list}';

    /** @var string */
    protected $description = 'List the Jev models available to the account';

    /**
     * Print the models.
     *
     * Example:
     * ```bash
     * php artisan jev:models
     * ```
     *
     * @param  Jev  $jev  The Jev client.
     * @return int Exit code: 0 on success, 1 when Jev cannot be reached.
     */
    public function handle(Jev $jev): int
    {
        try {
            $models = $jev->models();
        } catch (JevException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line((string) json_encode($models, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->components->bulletList($models);

        return self::SUCCESS;
    }
}
