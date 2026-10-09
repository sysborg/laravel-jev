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
use Throwable;

/**
 * Structured logs of each call, always with its correlation id:
 * debug when it starts and succeeds, error when it fails (retries are logged
 * as warnings by the Retry stage). Only the redacted state preview is logged.
 */
final readonly class Log implements Middleware
{
    /**
     * Create the stage.
     *
     * Example:
     * ```php
     * new Log($logger, $clock);
     * ```
     *
     * @param  LoggerInterface  $logger  The package log channel.
     * @param  Clock  $clock  Measures failed calls.
     */
    public function __construct(
        private LoggerInterface $logger,
        private Clock $clock,
    ) {}

    /**
     * Log the call.
     *
     * Example:
     * ```php
     * $stage->handle($call, $next); // "Jev call started" ... "Jev call succeeded"
     * ```
     *
     * @param  Call  $call  The call.
     * @param  Closure(Call): (DecisionResult|WebContextResult)  $next  The rest of the pipeline.
     * @return DecisionResult|WebContextResult The result, unchanged.
     *
     * @throws JevException When the call fails (after it was logged).
     */
    public function handle(Call $call, Closure $next): DecisionResult|WebContextResult
    {
        $base = [
            'correlation_id' => (string) $call->correlationId,
            'operation' => $call->operation->value,
            'model' => $call->model(),
            'connection' => $call->connection,
        ];

        $this->safely(fn () => $this->logger->debug('Jev call started.', [...$base, 'state' => $call->statePreview]));

        try {
            $result = $next($call);
        } catch (JevException $e) {
            $this->safely(fn () => $this->logger->error('Jev call failed.', [
                ...$base,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'http_status' => $e->httpStatus,
                'error_code' => $e->errorCode,
                'may_have_been_billed' => $e->mayHaveBeenBilled(),
                'attempts' => max(1, $call->attempts),
                'latency_ms' => $call->elapsedMs($this->clock->monotonicMs()),
            ]));

            throw $e;
        }

        $usage = $result instanceof DecisionResult ? $result->usage : $result->usage->toUsage();

        $this->safely(fn () => $this->logger->debug('Jev call succeeded.', [
            ...$base,
            'model' => $result->meta->model,
            'run_id' => $result->meta->runId,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'billing_mode' => $result->billing->mode->value,
            'input_tokens_charged' => $result->billing->inputTokensCharged,
            'credits_charged' => $result->billing->creditsCharged,
            'attempts' => $result->meta->attempts,
            'latency_ms' => $result->meta->latencyMs,
        ]));

        return $result;
    }

    /**
     * Log without ever breaking the call.
     *
     * Example:
     * ```php
     * $this->safely(fn () => $this->logger->debug('...'));
     * ```
     *
     * @param  Closure(): void  $log  The logging call.
     * @return void Nothing.
     */
    private function safely(Closure $log): void
    {
        try {
            $log();
        } catch (Throwable) {
            // A broken log channel must not break the Jev call.
        }
    }
}
