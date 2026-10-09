<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Ports\Driven;

use Sysborg\LaravelJevai\Domain\Events\JevEvent;

/**
 * Delivers package events to the host application (e.g. Laravel's dispatcher).
 *
 * @api
 */
interface EventPublisher
{
    /**
     * Publish an event.
     *
     * Listener failures must never break the Jev call that emitted the event:
     * the application layer catches and logs anything thrown here.
     *
     * Example:
     * ```php
     * $publisher->publish(new DecisionSucceeded($request, $result, $clock->now()));
     * ```
     *
     * @param  JevEvent  $event  The event to deliver.
     * @return void Nothing.
     */
    public function publish(JevEvent $event): void;
}
