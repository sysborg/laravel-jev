# Architecture

The package follows **ports & adapters** (hexagonal architecture). The rules are enforced by
architecture tests (`tests/Architecture`).

```
                      ┌──────────────────────── your Laravel app ────────────────────────┐
                      │   Jev facade · DecisionBuilder · WebContextBuilder · JevUsage      │
                      └───────────────────────────────┬──────────────────────────────────┘
                                                      │ driving port: Ports\Driving\Jev
┌─────────────────────────────────────────────────────▼─────────────────────────────────────────────┐
│ Application  JevClient → use cases → Pipeline (stages below) → gateway                              │
│ Domain       questions, answers, requests, results, usage, billing, events, exceptions (pure PHP)   │
└──────┬───────────┬───────────┬────────────┬───────────┬───────────┬───────────┬───────────┬────────┘
       │ driven ports (interfaces in Ports\Driven)                                             
 DecisionGateway  EventPublisher  UsageRepository  Tracer  MetricsRecorder  CounterStore  DecisionQueue  ...
       │               │                │             │           │              │             │
 HttpDecisionGateway  Laravel events  Eloquent/Null  Log/OTel   OTel/Pulse   Laravel cache   Laravel queue
 JevFake (tests)                                                                            JevFake (tests)
```

| Layer | Namespace | May depend on |
|---|---|---|
| Domain | `Sysborg\LaravelJevai\Domain` | nothing (plain PHP) |
| Ports | `Sysborg\LaravelJevai\Ports` | Domain (interfaces only) |
| Application | `Sysborg\LaravelJevai\Application` | Domain, Ports, PSR-3 |
| Adapters | `Sysborg\LaravelJevai\Adapters` | anything (Laravel, Guzzle, OpenTelemetry, Pulse) |
| Composition root | `JevServiceProvider` | everything |

## The call pipeline

Every call goes through the same stages, outermost first:

```
Correlate → Validate → Redact → Log → Alert → Emit → Record → Measure → Budget → Trace → Retry → Protect → gateway
```

| Stage | Once per | Does |
|---|---|---|
| Correlate | call | UUIDv7 correlation id; sets `trace.correlation_id` and a fallback `session_id`; connection and default model |
| Validate | call | rejects bodies over 256,000 bytes before anything is sent |
| Redact | call | redacted state preview for observability; removes denied trace fields |
| Log | call | structured debug/error logs |
| Alert | call | `CreditsExhausted`, `JudgeRulesChanged`, `BalanceLow` |
| Emit | call | `DecisionRequested`, then `DecisionSucceeded`/`WebContextResolved` + `TokenUsageRecorded`, or `DecisionFailed` |
| Record | call | one usage record (success or final failure) |
| Measure | call | metrics |
| Budget | call | daily charged-token budget |
| Trace | call | one span covering every attempt |
| Retry | call | retry policy, `RetryScheduled`, final attempts and latency |
| Protect | **attempt** | rate limiter and circuit breaker |
| gateway | attempt | exactly one HTTP request, no side effects |

Gateways never retry, log or emit: that is why the fake behaves exactly like the HTTP adapter.
Emit, Record, Measure, Alert, Log and the limit stores **never break a call**: their own failures
are logged as warnings.

## Writing your own adapter

Implement a port and bind it in a service provider registered after the package's:

```php
use Sysborg\LaravelJevai\Domain\Usage\{RunRecord, UsageQuery};
use Sysborg\LaravelJevai\Ports\Driven\UsageRepository;

final class ClickHouseUsageRepository implements UsageRepository
{
    public function record(RunRecord $record): void { /* insert */ }
    public function summarize(UsageQuery $query): array { /* aggregate into UsageSummary[] */ }
    public function prune(DateTimeImmutable $before): int { /* delete */ }
}

// AppServiceProvider::register()
$this->app->singleton(UsageRepository::class, ClickHouseUsageRepository::class);
```

The same applies to `Tracer`, `MetricsRecorder`, `EventPublisher`, `CounterStore`, `Debouncer`,
`DecisionQueue`, `Clock` and `IdGenerator`. Run the contract suite in `tests/Feature/Contract` to
check a custom `DecisionGateway`.
