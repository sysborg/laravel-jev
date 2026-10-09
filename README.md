# laravel-jevai

> **Status: pre-release.** Under active development towards an open beta. Not ready for use yet.

Laravel package for the [Jev AI decision API](https://jev-ai.pro/docs), built with ports & adapters,
with first-class observability: lifecycle events, token usage and billing tracking, tracing and metrics.

## Requirements

- PHP 8.3+
- Laravel 11, 12 or 13

## Installation

```bash
composer require sysborg/laravel-jevai
```

```dotenv
JEV_AI_API_KEY=your-key
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=jev-config
```

## Quick start

```php
use Sysborg\LaravelJevai\Facades\Jev;

$result = Jev::state($ticket->body)
    ->noul('is_urgent', 'Does this convey urgency?')
    ->choice('department', 'Which team should handle this?', [
        'billing' => 'Payments and refunds',
        'technical' => 'Bugs and outages',
    ])
    ->score('frustration', 'How frustrated is the customer?', ['Calm', 'Frustrated', 'Very angry'])
    ->context('ticket_id', $ticket->id)   // stays in your app, attached to every event
    ->evaluate();

$result->choice('department')->choice;    // 'billing'
$result->usage->inputTokens;              // 120
$result->billing->inputTokensCharged;     // 120
$result->meta->correlationId;             // UUIDv7, also sent in Jev's private trace
```

Saved judges and web context:

```php
Jev::judge('judge_123', revision: 3)->state($transcript)->evaluate();

Jev::webContext('Has OpenAI released GPT-6?')->numResults(6)->resolve()->isYes();
```

### Responses through events

Every call dispatches Laravel events: `DecisionRequested`, then `DecisionSucceeded` /
`WebContextResolved` + `TokenUsageRecorded`, or `DecisionFailed`; `RetryScheduled` for each retry.
Alerts follow: `CreditsExhausted` (402), `JudgeRulesChanged` (409 on a pinned judge) and
`BalanceLow` (set `JEV_AI_LOW_BALANCE_TOKENS`; at most once per hour). Every event carries the
correlation id, your `context`, the connection and the model. A failing listener never breaks the call.

```php
use Sysborg\LaravelJevai\Domain\Events\DecisionSucceeded;

Event::listen(function (DecisionSucceeded $event) {
    Ticket::find($event->context()->get('ticket_id'))
        ->routeTo($event->result->choice('department')->choice);
});

// Background evaluation: the result arrives through the same events.
$pending = Jev::state($ticket->body)->noul('is_urgent', 'Urgent?')->context('ticket_id', $ticket->id)->queue();
```

### Retries and billing safety

429 (honoring `Retry-After`) and 502/503/504 are retried with backoff. Timeouts are **not**
retried by default: Jev has no idempotency key, so a timed-out call may already have been billed.

## Roadmap

See [docs/open-beta-tasks.md](docs/open-beta-tasks.md).

## License

MIT. See [LICENSE](LICENSE).
