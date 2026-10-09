<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Events;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;

/**
 * A call to Jev is about to start (validated, before the first attempt).
 *
 * @api
 */
final readonly class DecisionRequested implements JevEvent
{
    use DescribesCall;

    public const string NAME = 'jev.decision.requested';

    /**
     * Create the event.
     *
     * Example:
     * ```php
     * new DecisionRequested(RunOperation::Decision, $correlationId, $context, $now,
     *     model: 'jev-latest', questionIds: ['is_urgent'], statePreview: 'My card was charged…');
     * ```
     *
     * @param  RunOperation  $operation  Which Jev operation is called.
     * @param  CorrelationId  $correlationId  Id of the call.
     * @param  Context  $context  Local data attached to the call.
     * @param  DateTimeImmutable  $occurredAt  When the call started.
     * @param  string|null  $model  Model of the call: the requested one, or the connection default.
     * @param  list<string>  $questionIds  Ids of inline questions (empty for judge and web-context calls).
     * @param  string|null  $judgeId  Saved judge id, for judge calls.
     * @param  int|null  $judgeRevision  Pinned judge revision, for judge calls.
     * @param  string|null  $sessionId  Jev `session_id` label.
     * @param  string|null  $user  Jev `user` label.
     * @param  string|null  $statePreview  The state (or web question) after redaction; null when omitted.
     * @param  string|null  $connection  Package connection used for the call.
     */
    public function __construct(
        public RunOperation $operation,
        public CorrelationId $correlationId,
        public Context $context,
        public DateTimeImmutable $occurredAt,
        public ?string $model = null,
        public array $questionIds = [],
        public ?string $judgeId = null,
        public ?int $judgeRevision = null,
        public ?string $sessionId = null,
        public ?string $user = null,
        public ?string $statePreview = null,
        public ?string $connection = null,
    ) {}

    /**
     * The event name.
     *
     * Example:
     * ```php
     * $event->name(); // 'jev.decision.requested'
     * ```
     *
     * @return string Always {@see self::NAME}.
     */
    public function name(): string
    {
        return self::NAME;
    }
}
