<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline\Stages;

use Closure;
use Sysborg\LaravelJevai\Application\Pipeline\Call;
use Sysborg\LaravelJevai\Application\Pipeline\Middleware;
use Sysborg\LaravelJevai\Application\Support\Redactor;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;

/**
 * Prepares the redacted state preview for observability and strips denied trace fields.
 */
final readonly class Redact implements Middleware
{
    /**
     * Create the stage.
     *
     * Example:
     * ```php
     * new Redact(Redactor::fromSpec('truncate:200', ['email']));
     * ```
     *
     * @param  Redactor  $redactor  The redaction rules.
     */
    public function __construct(
        private Redactor $redactor,
    ) {}

    /**
     * Redact, then continue.
     *
     * Example:
     * ```php
     * $stage->handle($call, $next); // $call->statePreview is now set
     * ```
     *
     * @param  Call  $call  The call.
     * @param  Closure(Call): (DecisionResult|WebContextResult)  $next  The rest of the pipeline.
     * @return DecisionResult|WebContextResult The result.
     *
     * @throws JevException When the call fails.
     */
    public function handle(Call $call, Closure $next): DecisionResult|WebContextResult
    {
        if ($call->request instanceof DecisionRequest) {
            $call->statePreview = $this->redactor->preview($call->request->state->value);
            $call->request = $call->request->withTrace($this->redactor->trace($call->request->trace));
        } else {
            $call->statePreview = $this->redactor->preview($call->request->question);
        }

        return $next($call);
    }
}
