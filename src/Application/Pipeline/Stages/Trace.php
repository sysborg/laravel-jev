<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline\Stages;

use Closure;
use Sysborg\LaravelJevai\Application\Pipeline\Call;
use Sysborg\LaravelJevai\Application\Pipeline\Middleware;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Ports\Driven\Span;
use Sysborg\LaravelJevai\Ports\Driven\Tracer;

/**
 * Wraps the call, retries included, in one span with GenAI semantic-convention attributes.
 */
final readonly class Trace implements Middleware
{
    /**
     * Create the stage.
     *
     * Example:
     * ```php
     * new Trace($tracer);
     * ```
     *
     * @param  Tracer  $tracer  Where spans go.
     */
    public function __construct(
        private Tracer $tracer,
    ) {}

    /**
     * Trace the call.
     *
     * Example:
     * ```php
     * $stage->handle($call, $next); // span "jev.decision" with gen_ai.* attributes
     * ```
     *
     * @param  Call  $call  The call.
     * @param  Closure(Call): (DecisionResult|WebContextResult)  $next  The rest of the pipeline.
     * @return DecisionResult|WebContextResult The result, unchanged.
     *
     * @throws JevException When the call fails (recorded on the span by the tracer).
     */
    public function handle(Call $call, Closure $next): DecisionResult|WebContextResult
    {
        $attributes = [
            'gen_ai.system' => 'jev',
            'gen_ai.operation.name' => $call->operation->value,
            'gen_ai.request.model' => $call->model(),
            'jev.correlation_id' => (string) $call->correlationId,
        ];

        return $this->tracer->span(
            'jev.'.$call->operation->value,
            $attributes,
            function (Span $span) use ($call, $next): DecisionResult|WebContextResult {
                try {
                    $result = $next($call);
                } catch (JevException $e) {
                    $span->setAttributes([
                        'jev.attempts' => max(1, $call->attempts),
                        'http.response.status_code' => $e->httpStatus,
                        'jev.error.code' => $e->errorCode,
                    ]);

                    throw $e;
                }

                $usage = $result instanceof DecisionResult ? $result->usage : $result->usage->toUsage();

                $span->setAttributes([
                    'gen_ai.response.model' => $result->meta->model,
                    'gen_ai.response.id' => $result->meta->responseId,
                    'gen_ai.usage.input_tokens' => $usage->inputTokens,
                    'gen_ai.usage.output_tokens' => $usage->outputTokens,
                    'jev.billing.mode' => $result->billing->mode->value,
                    'jev.tokens_charged' => $result->billing->inputTokensCharged,
                    'jev.credits_charged' => $result->billing->creditsCharged,
                    'jev.run_id' => $result->meta->runId,
                    'jev.connection' => $result->meta->connection,
                    'jev.attempts' => $result->meta->attempts,
                ]);

                return $result;
            },
        );
    }
}
