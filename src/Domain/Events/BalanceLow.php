<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Events;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;

/**
 * The paid input tokens left on the account dropped below the configured threshold.
 *
 * Published at most once per debounce window and connection, by the call that
 * observed the low balance (`X-Jev-Tokens-Remaining`).
 */
final readonly class BalanceLow implements JevEvent
{
    use DescribesCall;

    public const string NAME = 'jev.balance.low';

    /**
     * Create the event.
     *
     * Example:
     * ```php
     * new BalanceLow($id, $context, $now, tokensRemaining: 8_000, threshold: 10_000, connection: 'default');
     * ```
     *
     * @param  CorrelationId  $correlationId  Id of the call that observed the balance.
     * @param  Context  $context  Local data attached to that call.
     * @param  DateTimeImmutable  $occurredAt  When the balance was observed.
     * @param  int  $tokensRemaining  Paid input tokens left after the call.
     * @param  int  $threshold  Configured alert threshold.
     * @param  string|null  $connection  Package connection whose account is low.
     * @param  string|null  $model  Model of the call that observed the balance.
     */
    public function __construct(
        public CorrelationId $correlationId,
        public Context $context,
        public DateTimeImmutable $occurredAt,
        public int $tokensRemaining,
        public int $threshold,
        public ?string $connection = null,
        public ?string $model = null,
    ) {}

    /**
     * The event name.
     *
     * Example:
     * ```php
     * $event->name(); // 'jev.balance.low'
     * ```
     *
     * @return string Always {@see self::NAME}.
     */
    public function name(): string
    {
        return self::NAME;
    }
}
