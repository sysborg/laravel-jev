# laravel-jevai

> 🇧🇷 [Leia em português](README.pt-BR.md)

> **Open beta (`0.x`).** See [what that means](docs/en/beta.md).

Laravel package for the [Jev AI decision API](https://jev-ai.pro/docs), built with ports & adapters
and first-class observability: every call's response, token usage and billing come back to your
app through Laravel events, logs, traces, metrics, a Pulse card and a usage table.

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
$result->meta->correlationId;             // UUIDv7, shared by events, logs, spans and Jev's trace
```

## Highlights

- **Typed decisions**: `noul`, `choice` and `score` questions, saved judges (`Jev::judge()`), and
  web context (`Jev::webContext()`), with immutable fluent builders.
- **Responses through events**: `DecisionSucceeded`, `TokenUsageRecorded`, `DecisionFailed`,
  `RetryScheduled`, `BalanceLow`, `CreditsExhausted`… all serializable, with your `context`.
  `->queue()` evaluates in the background and delivers through the same events.
- **Billing-safe retries**: 429 (`Retry-After`) and 5xx are retried; timeouts are not, because Jev
  has no idempotency key. Optional rate limiter, circuit breaker and daily token budget.
- **Observability**: structured logs, OpenTelemetry spans and metrics, Laravel Pulse card,
  `jev_runs` usage table with `JevUsage` reports and `php artisan jev:usage`.
- **Secure by default**: the API key never appears in logs, events, spans, exceptions, dumps or
  queued jobs; the request state is redacted everywhere it is observed.
- **Testable**: `Jev::fake([...])` runs the real pipeline without HTTP, with assertions.

## Requirements

PHP 8.3+ · Laravel 11.45+, 12 or 13

## Install

```bash
composer require sysborg/laravel-jevai
```

```dotenv
JEV_AI_API_KEY=your-key
```

## Documentation

| | |
|---|---|
| [Getting started](docs/en/getting-started.md) | install, first decision, judges, web context, queue, errors |
| [Configuration](docs/en/configuration.md) | every config key and env variable |
| [Architecture](docs/en/architecture.md) | ports & adapters, the call pipeline, custom adapters |
| [Observability](docs/en/observability.md) | events catalog, logs, tracing, metrics, Pulse, usage reports |
| [Billing, retries and limits](docs/en/billing-and-retries.md) | what is billed, what is retried, client-side limits |
| [Security](docs/en/security.md) | API key handling, redaction, what leaves your app |
| [Testing](docs/en/testing.md) | `Jev::fake()`, assertions, live smoke tests |
| [Open beta](docs/en/beta.md) | stability guarantees, known limitations, reporting issues |

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
