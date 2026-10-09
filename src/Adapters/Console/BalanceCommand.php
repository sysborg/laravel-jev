<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Console;

use Illuminate\Console\Command;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Ports\Driving\Jev;

/**
 * `php artisan jev:balance`: remaining credits and paid input tokens (free, no inference).
 */
final class BalanceCommand extends Command
{
    /** @var string */
    protected $signature = 'jev:balance {--json : Print JSON instead of text}';

    /** @var string */
    protected $description = 'Show the remaining Jev credits and paid input tokens';

    /**
     * Print the balance.
     *
     * Example:
     * ```bash
     * php artisan jev:balance --json
     * ```
     *
     * @param  Jev  $jev  The Jev client.
     * @return int Exit code: 0 on success, 1 when Jev cannot be reached.
     */
    public function handle(Jev $jev): int
    {
        try {
            $balance = $jev->balance();
        } catch (JevException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->line((string) json_encode([
                'credits_remaining' => $balance->creditsRemaining,
                'paid_input_tokens_remaining' => $balance->paidInputTokensRemaining,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Credits remaining', (string) $balance->creditsRemaining);
        $this->components->twoColumnDetail('Paid input tokens remaining', number_format($balance->paidInputTokensRemaining));

        if ($balance->isExhausted()) {
            $this->components->warn('The account cannot pay for another call.');
        }

        return self::SUCCESS;
    }
}
