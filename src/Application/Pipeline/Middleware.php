<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Pipeline;

use Closure;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;

/**
 * One stage of the call pipeline.
 */
interface Middleware
{
    /**
     * Handle the call, delegating to the next stage.
     *
     * Example:
     * ```php
     * public function handle(Call $call, Closure $next): DecisionResult|WebContextResult
     * {
     *     // before
     *     $result = $next($call);
     *     // after
     *     return $result;
     * }
     * ```
     *
     * @param  Call  $call  The call.
     * @param  Closure(Call): (DecisionResult|WebContextResult)  $next  The rest of the pipeline.
     * @return DecisionResult|WebContextResult The result of the call.
     *
     * @throws JevException When the call fails.
     */
    public function handle(Call $call, Closure $next): DecisionResult|WebContextResult;
}
