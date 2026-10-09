<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline\Stages;

use Closure;
use Sysborg\LaravelJevai\Application\Pipeline\Call;
use Sysborg\LaravelJevai\Application\Pipeline\Middleware;
use Sysborg\LaravelJevai\Application\Support\RequestValidator;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;

/**
 * Rejects requests Jev would refuse, before anything is sent, emitted or recorded.
 */
final readonly class Validate implements Middleware
{
    /**
     * Create the stage.
     *
     * Example:
     * ```php
     * new Validate(new RequestValidator('jev-latest'));
     * ```
     *
     * @param  RequestValidator  $validator  The checks to run.
     */
    public function __construct(
        private RequestValidator $validator,
    ) {}

    /**
     * Validate the request, then continue.
     *
     * Example:
     * ```php
     * $stage->handle($call, $next); // throws InvalidValue for a 300 KB body
     * ```
     *
     * @param  Call  $call  The call.
     * @param  Closure(Call): (DecisionResult|WebContextResult)  $next  The rest of the pipeline.
     * @return DecisionResult|WebContextResult The result.
     *
     * @throws InvalidValue When the request breaks a Jev limit.
     * @throws JevException When the call fails.
     */
    public function handle(Call $call, Closure $next): DecisionResult|WebContextResult
    {
        $this->validator->validate($call->request);

        return $next($call);
    }
}
