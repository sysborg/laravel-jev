<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline\Stages;

use Closure;
use Psr\Log\LoggerInterface;
use Sysborg\LaravelJevai\Application\Pipeline\Call;
use Sysborg\LaravelJevai\Application\Pipeline\Middleware;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\MetricsRecorder;
use Throwable;

/**
 * Records request, token, credit and latency metrics for each call.
 *
 * A metrics failure is logged and never breaks the call.
 */
final readonly class Measure implements Middleware
{
    public const string REQUESTS = 'jev.requests';

    public const string INPUT_TOKENS = 'jev.tokens.input';

    public const string OUTPUT_TOKENS = 'jev.tokens.output';

    public const string TOKENS_CHARGED = 'jev.tokens.charged';

    public const string CREDITS_CHARGED = 'jev.credits.charged';

    public const string LATENCY = 'jev.latency';

    public const string ATTEMPTS = 'jev.attempts';

    /**
     * Create the stage.
     *
     * Example:
     * ```php
     * new Measure($metrics, $clock, $logger);
     * ```
     *
     * @param  MetricsRecorder  $metrics  Where metrics go.
     * @param  Clock  $clock  Measures failed calls.
     * @param  LoggerInterface  $logger  Receives metric failures.
     */
    public function __construct(
        private MetricsRecorder $metrics,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * Measure the call.
     *
     * Example:
     * ```php
     * $stage->handle($call, $next); // jev.requests{status=success} += 1, ...
     * ```
     *
     * @param  Call  $call  The call.
     * @param  Closure(Call): (DecisionResult|WebContextResult)  $next  The rest of the pipeline.
     * @return DecisionResult|WebContextResult The result, unchanged.
     *
     * @throws JevException When the call fails (after it was measured).
     */
    public function handle(Call $call, Closure $next): DecisionResult|WebContextResult
    {
        try {
            $result = $next($call);
        } catch (JevException $e) {
            $this->safely($call, function () use ($call, $e): void {
                $attributes = [
                    'operation' => $call->operation->value,
                    'model' => $call->model() ?? 'unknown',
                    'status' => 'error',
                    'error' => (new \ReflectionClass($e))->getShortName(),
                ];

                $this->metrics->increment(self::REQUESTS, 1, $attributes);
                $this->metrics->histogram(self::LATENCY, $call->elapsedMs($this->clock->monotonicMs()), $attributes);
                $this->metrics->histogram(self::ATTEMPTS, max(1, $call->attempts), $attributes);
            });

            throw $e;
        }

        $this->safely($call, function () use ($call, $result): void {
            $usage = $result instanceof DecisionResult ? $result->usage : $result->usage->toUsage();
            $attributes = [
                'operation' => $call->operation->value,
                'model' => $result->meta->model,
                'status' => 'success',
                'billing_mode' => $result->billing->mode->value,
            ];

            $this->metrics->increment(self::REQUESTS, 1, $attributes);
            $this->metrics->increment(self::INPUT_TOKENS, $usage->inputTokens, $attributes);
            $this->metrics->increment(self::OUTPUT_TOKENS, $usage->outputTokens, $attributes);
            $this->metrics->increment(self::TOKENS_CHARGED, $result->billing->inputTokensCharged, $attributes);
            $this->metrics->increment(self::CREDITS_CHARGED, $result->billing->creditsCharged, $attributes);
            $this->metrics->histogram(self::LATENCY, $result->meta->latencyMs, $attributes);
            $this->metrics->histogram(self::ATTEMPTS, $result->meta->attempts, $attributes);
        });

        return $result;
    }

    /**
     * Run metric calls, logging any failure.
     *
     * Example:
     * ```php
     * $this->safely($call, fn () => $this->metrics->increment('jev.requests'));
     * ```
     *
     * @param  Call  $call  The call, for log context.
     * @param  Closure(): void  $record  The metric calls.
     * @return void Nothing.
     */
    private function safely(Call $call, Closure $record): void
    {
        try {
            $record();
        } catch (Throwable $e) {
            $this->logger->warning('Recording Jev metrics failed; the Jev call was not affected.', [
                'correlation_id' => (string) $call->correlationId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
