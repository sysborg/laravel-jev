<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Sysborg\LaravelJevai\Domain\Events\JevEvent;
use Sysborg\LaravelJevai\Ports\Driven\EventPublisher;

/**
 * Publishes package events through Laravel's event dispatcher.
 *
 * Listen with `Event::listen(DecisionSucceeded::class, ...)`; queued listeners work
 * because every event is serializable.
 */
final readonly class LaravelEventPublisher implements EventPublisher
{
    /**
     * Create the publisher.
     *
     * Example:
     * ```php
     * new LaravelEventPublisher(app('events'));
     * ```
     *
     * @param  Dispatcher  $events  Laravel's event dispatcher.
     */
    public function __construct(
        private Dispatcher $events,
    ) {}

    /**
     * Dispatch the event.
     *
     * Example:
     * ```php
     * $publisher->publish($event); // same as event($event)
     * ```
     *
     * @param  JevEvent  $event  The event.
     * @return void Nothing.
     */
    public function publish(JevEvent $event): void
    {
        $this->events->dispatch($event);
    }
}
