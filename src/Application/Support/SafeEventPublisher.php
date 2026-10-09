<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Support;

use Psr\Log\LoggerInterface;
use Sysborg\LaravelJevai\Domain\Events\JevEvent;
use Sysborg\LaravelJevai\Ports\Driven\EventPublisher;
use Throwable;

/**
 * Publishes events without ever letting a listener break the Jev call.
 */
final readonly class SafeEventPublisher
{
    /**
     * Create the publisher.
     *
     * Example:
     * ```php
     * new SafeEventPublisher($publisher, $logger, enabled: config('jev.events.enabled'));
     * ```
     *
     * @param  EventPublisher  $publisher  Where events go (e.g. Laravel's dispatcher).
     * @param  LoggerInterface  $logger  Receives listener failures.
     * @param  bool  $enabled  When false, nothing is published.
     */
    public function __construct(
        private EventPublisher $publisher,
        private LoggerInterface $logger,
        private bool $enabled = true,
    ) {}

    /**
     * Publish an event, logging instead of throwing when a listener fails.
     *
     * Example:
     * ```php
     * $events->publish(new DecisionRequested(...));
     * ```
     *
     * @param  JevEvent  $event  The event.
     * @return void Nothing.
     */
    public function publish(JevEvent $event): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            $this->publisher->publish($event);
        } catch (Throwable $e) {
            $this->logger->warning('A Jev event listener failed; the Jev call was not affected.', [
                'event' => $event->name(),
                'correlation_id' => (string) $event->correlationId(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether events are published at all.
     *
     * Example:
     * ```php
     * if ($events->isEnabled()) { ... }
     * ```
     *
     * @return bool True when enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
