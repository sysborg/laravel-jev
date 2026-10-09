<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Events;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;

/**
 * A web-context call succeeded. Carries the full result.
 *
 * @api
 */
final readonly class WebContextResolved implements JevEvent
{
    use DescribesCall;

    public const string NAME = 'jev.web_context.resolved';

    public CorrelationId $correlationId;

    /**
     * Create the event.
     *
     * Example:
     * ```php
     * new WebContextResolved($result, $request->context, $now);
     * ```
     *
     * @param  WebContextResult  $result  Decision, evidence, usage, latency, billing and metadata.
     * @param  Context  $context  Local data attached to the call.
     * @param  DateTimeImmutable  $occurredAt  When the call finished.
     */
    public function __construct(
        public WebContextResult $result,
        public Context $context,
        public DateTimeImmutable $occurredAt,
    ) {
        $this->correlationId = $result->meta->correlationId;
    }

    /**
     * The event name.
     *
     * Example:
     * ```php
     * $event->name(); // 'jev.web_context.resolved'
     * ```
     *
     * @return string Always {@see self::NAME}.
     */
    public function name(): string
    {
        return self::NAME;
    }
}
