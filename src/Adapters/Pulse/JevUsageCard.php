<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Pulse;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;
use stdClass;

/**
 * Pulse card with Jev calls, errors, tokens, credits and latency per model.
 *
 * Add it to your Pulse dashboard (`resources/views/vendor/pulse/dashboard.blade.php`):
 * `<livewire:jev.usage cols="6" />`. Requires `JEV_AI_METRICS=pulse`.
 */
#[Lazy]
final class JevUsageCard extends Card
{
    /**
     * View rendering the card, registered under the `jev` namespace by the service provider.
     *
     * @var view-string
     */
    private const string VIEW = 'jev::pulse.usage';

    /**
     * Render the card.
     *
     * Example:
     * ```blade
     * <livewire:jev.usage cols="6" />
     * ```
     *
     * @return Renderable The card view.
     */
    public function render(): Renderable
    {
        [$models, $time, $runAt] = $this->remember(fn (): Collection => $this->models(), 'models');

        return View::make(self::VIEW, [
            'models' => $models,
            'time' => $time,
            'runAt' => $runAt,
        ]);
    }

    /**
     * Aggregate the Jev entries of the selected period, one row per model.
     *
     * Example:
     * ```php
     * $this->models(); // collect([(object) ['model' => 'clef', 'calls' => 12, ...]])
     * ```
     *
     * @return Collection<int, stdClass> Rows (model, calls, errors, tokens, credits, avg, max) ordered by tokens charged.
     */
    private function models(): Collection
    {
        $calls = self::column($this->aggregate(PulseMetricsRecorder::CALLS, 'count'), 'count');
        $errors = self::column($this->aggregate(PulseMetricsRecorder::ERRORS, 'count'), 'count');
        $tokens = self::column($this->aggregate(PulseMetricsRecorder::TOKENS_CHARGED, 'sum'), 'sum');
        $credits = self::column($this->aggregate(PulseMetricsRecorder::CREDITS_CHARGED, 'sum'), 'sum');
        $latency = $this->aggregate(PulseMetricsRecorder::LATENCY, ['avg', 'max']);
        $average = self::column($latency, 'avg');
        $maximum = self::column($latency, 'max');

        $rows = [];

        foreach ($calls as $model => $count) {
            $row = new stdClass;
            $row->model = $model;
            $row->calls = $count;
            $row->errors = $errors[$model] ?? 0;
            $row->tokens = $tokens[$model] ?? 0;
            $row->credits = ($credits[$model] ?? 0) / PulseMetricsRecorder::CREDITS_SCALE;
            $row->avg = $average[$model] ?? 0;
            $row->max = $maximum[$model] ?? 0;
            $rows[] = $row;
        }

        usort($rows, static fn (stdClass $a, stdClass $b): int => $b->tokens <=> $a->tokens);

        return collect($rows);
    }

    /**
     * Index one aggregate column of Pulse rows by entry key.
     *
     * Example:
     * ```php
     * self::column($this->aggregate('jev_call', 'count'), 'count'); // ['clef' => 12, 'jev-latest' => 30]
     * ```
     *
     * @param  Collection<array-key, mixed>  $rows  Rows returned by `aggregate()`.
     * @param  string  $field  The aggregate (`count`, `sum`, `avg`, `max`).
     * @return array<string, int> Key => rounded value.
     */
    private static function column(Collection $rows, string $field): array
    {
        $values = [];

        foreach ($rows as $row) {
            if (! is_object($row) || ! isset($row->key) || ! is_scalar($row->key)) {
                continue;
            }

            $value = $row->{$field} ?? 0;
            $values[(string) $row->key] = is_numeric($value) ? (int) round((float) $value) : 0;
        }

        return $values;
    }
}
