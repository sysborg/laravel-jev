<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Pulse;

use Laravel\Pulse\Pulse;
use Sysborg\LaravelJevai\Ports\Driven\MetricsRecorder;

/**
 * {@see MetricsRecorder} on Laravel Pulse, feeding the Jev Pulse card.
 *
 * Entries are keyed by model. Pulse stores integers, so credits are recorded
 * in millionths ({@see self::CREDITS_SCALE}).
 */
final readonly class PulseMetricsRecorder implements MetricsRecorder
{
    public const string CALLS = 'jev_call';

    public const string ERRORS = 'jev_error';

    public const string INPUT_TOKENS = 'jev_input_tokens';

    public const string TOKENS_CHARGED = 'jev_tokens_charged';

    public const string CREDITS_CHARGED = 'jev_credits_charged';

    public const string LATENCY = 'jev_latency';

    public const int CREDITS_SCALE = 1_000_000;

    /**
     * Create the recorder.
     *
     * Example:
     * ```php
     * new PulseMetricsRecorder(app(Pulse::class));
     * ```
     *
     * @param  Pulse  $pulse  The Pulse instance.
     */
    public function __construct(
        private Pulse $pulse,
    ) {}

    /**
     * Record the counters the card shows; other metrics are ignored.
     *
     * Example:
     * ```php
     * $metrics->increment('jev.tokens.charged', 120, ['model' => 'jev-latest']);
     * ```
     *
     * @param  string  $name  Metric name.
     * @param  int|float  $value  Amount.
     * @param  array<string, string|int|float|bool|null>  $attributes  Dimensions; `model` and `status` are used.
     * @return void Nothing.
     */
    public function increment(string $name, int|float $value = 1, array $attributes = []): void
    {
        $model = self::model($attributes);

        match ($name) {
            'jev.requests' => $this->countRequest($model, $attributes['status'] ?? null),
            'jev.tokens.input' => $this->pulse->record(self::INPUT_TOKENS, $model, (int) $value)->sum(),
            'jev.tokens.charged' => $this->pulse->record(self::TOKENS_CHARGED, $model, (int) $value)->sum(),
            'jev.credits.charged' => $this->pulse->record(self::CREDITS_CHARGED, $model, (int) round($value * self::CREDITS_SCALE))->sum(),
            default => null,
        };
    }

    /**
     * Record the latency the card shows; other histograms are ignored.
     *
     * Example:
     * ```php
     * $metrics->histogram('jev.latency', 412, ['model' => 'jev-latest']);
     * ```
     *
     * @param  string  $name  Metric name.
     * @param  int|float  $value  Observation.
     * @param  array<string, string|int|float|bool|null>  $attributes  Dimensions; `model` is used.
     * @return void Nothing.
     */
    public function histogram(string $name, int|float $value, array $attributes = []): void
    {
        if ($name === 'jev.latency') {
            $this->pulse->record(self::LATENCY, self::model($attributes), (int) round($value))->avg()->max();
        }
    }

    /**
     * Count a call, and an error when it failed.
     *
     * Example:
     * ```php
     * $this->countRequest('clef', 'error');
     * ```
     *
     * @param  string  $model  Model key.
     * @param  mixed  $status  `success` or `error`.
     * @return void Nothing.
     */
    private function countRequest(string $model, mixed $status): void
    {
        $this->pulse->record(self::CALLS, $model)->count();

        if ($status === 'error') {
            $this->pulse->record(self::ERRORS, $model)->count();
        }
    }

    /**
     * The model dimension as an entry key.
     *
     * Example:
     * ```php
     * self::model(['model' => 'clef']); // 'clef'
     * ```
     *
     * @param  array<string, string|int|float|bool|null>  $attributes  Dimensions.
     * @return string The model, or `unknown`.
     */
    private static function model(array $attributes): string
    {
        $model = $attributes['model'] ?? null;

        return is_string($model) && $model !== '' ? $model : 'unknown';
    }
}
