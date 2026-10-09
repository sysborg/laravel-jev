# Observability

Everything a call does is observable without touching your code: Laravel events (with the full
response), structured logs, tracing spans, metrics, a Pulse card and a usage table.

All signals of a call share its **correlation id** (UUIDv7), which is also sent to Jev in the
private `trace` (and as `session_id` when you did not set one), so Jev's usage page can be matched
with your logs.

## Events

Events are plain, serializable objects dispatched through Laravel's dispatcher, so queued listeners
work. A listener that throws never breaks the call (it is logged). Turn them off with
`JEV_AI_EVENTS=false` (usage records keep working).

Every event implements `JevEvent`: `name()`, `correlationId()`, `context()` (your local data),
`occurredAt()`, and carries the `connection` and `model`.

| Event | `name()` | When | Main fields |
|---|---|---|---|
| `DecisionRequested` | `jev.decision.requested` | call validated, before the first attempt | `operation`, `model`, `questionIds`, `judgeId`, `judgeRevision`, `sessionId`, `user`, `statePreview` (redacted) |
| `DecisionSucceeded` | `jev.decision.succeeded` | decision or judge call succeeded | `operation`, `result` (`DecisionResult`; `raw` only with `events.include_raw`) |
| `WebContextResolved` | `jev.web_context.resolved` | web-context call succeeded | `result` (`WebContextResult`) |
| `TokenUsageRecorded` | `jev.usage.recorded` | after every success | `operation`, `usage`, `billing` |
| `DecisionFailed` | `jev.decision.failed` | any call failed after retries | `operation`, `exception` (class), `message`, `httpStatus`, `errorCode`, `retryable`, `mayHaveBeenBilled`, `attempts`, `latencyMs` |
| `RetryScheduled` | `jev.retry.scheduled` | before each retry | `failedAttempt`, `delayMs`, `reason`, `httpStatus`, `retryAfterSeconds`, `wasRateLimited()` |
| `CreditsExhausted` | `jev.credits.exhausted` | after a 402 | `message`, `errorCode` |
| `JudgeRulesChanged` | `jev.judge.rules_changed` | after a 409 on a pinned judge | `judgeId`, `pinnedRevision` |
| `BalanceLow` | `jev.balance.low` | tokens left < `JEV_AI_LOW_BALANCE_TOKENS` (once per hour and connection) | `tokensRemaining`, `threshold` |
| `CircuitOpened` / `CircuitClosed` | `jev.circuit.opened` / `.closed` | circuit breaker transitions | `consecutiveFailures`, `openSeconds` |

Order for a successful call: `DecisionRequested` → (`RetryScheduled`…) → `DecisionSucceeded` →
`TokenUsageRecorded` → (`BalanceLow`). For a failure: `DecisionRequested` → … → `DecisionFailed` →
(`CreditsExhausted` | `JudgeRulesChanged`).

```php
use Sysborg\LaravelJevai\Domain\Events\{DecisionFailed, TokenUsageRecorded};

Event::listen(function (TokenUsageRecorded $event) {
    Billing::addJevSpend($event->context()->get('tenant_id'), $event->billing->inputTokensCharged);
});

Event::listen(function (DecisionFailed $event) {
    if ($event->mayHaveBeenBilled) {
        report("Jev call {$event->correlationId()} timed out and may have been billed.");
    }
});
```

## Logs

With `JEV_AI_LOG_CALLS=true` (default), on `JEV_AI_LOG_CHANNEL` (or the default channel):

| Level | Message | Context |
|---|---|---|
| debug | `Jev call started.` | `correlation_id`, `operation`, `model`, `connection`, `state` (redacted preview) |
| debug | `Jev call succeeded.` | + `run_id`, tokens, billing, `attempts`, `latency_ms` |
| warning | `Jev attempt failed; retrying.` | `failed_attempt`, `delay_ms`, `exception`, `http_status` |
| error | `Jev call failed.` | `exception`, `message`, `http_status`, `error_code`, `may_have_been_billed`, `attempts` |

Package warnings (a failing listener, usage storage or metrics backend) are always logged.

## Tracing

`JEV_AI_TRACING=otel` creates one CLIENT span per call (all attempts inside it), made current so the
HTTP request nests under it. Configure the OpenTelemetry SDK/exporter in your application;
the package only uses `open-telemetry/api` (`composer require open-telemetry/api`).
`JEV_AI_TRACING=log` writes spans to the log at debug level instead.

| Attribute | Example |
|---|---|
| `gen_ai.system` | `jev` |
| `gen_ai.operation.name` | `decision`, `judge`, `web_context` |
| `gen_ai.request.model` / `gen_ai.response.model` | `jev-latest` |
| `gen_ai.response.id` | response `id` |
| `gen_ai.usage.input_tokens` / `output_tokens` | `120` / `8` |
| `jev.correlation_id`, `jev.run_id`, `jev.connection` | |
| `jev.billing.mode`, `jev.tokens_charged`, `jev.credits_charged` | `tokens`, `120`, `0` |
| `jev.attempts` | `2` |
| `http.response.status_code`, `jev.error.code` | on failure |

Spans never contain the request state.

## Metrics

`JEV_AI_METRICS=otel` (OpenTelemetry), `pulse` (Laravel Pulse) or `otel,pulse`:

| Metric | Type | Dimensions |
|---|---|---|
| `jev.requests` | counter | `operation`, `model`, `status` (`success`/`error`), `billing_mode` or `error` |
| `jev.tokens.input` / `jev.tokens.output` | counter | same |
| `jev.tokens.charged` | counter | same |
| `jev.credits.charged` | counter | same |
| `jev.latency` | histogram (ms) | same |
| `jev.attempts` | histogram | same |

## Pulse card

With `JEV_AI_METRICS=pulse`, add the card to `resources/views/vendor/pulse/dashboard.blade.php`:

```blade
<livewire:jev.usage cols="6" />
```

It shows calls, errors, tokens charged, credits and average/maximum latency per model.

## Usage table and reports

```bash
php artisan vendor:publish --tag=jev-migrations && php artisan migrate
```

```dotenv
JEV_AI_USAGE_DRIVER=database
```

One row per call in `jev_runs` (success or final failure):

| Column | Meaning |
|---|---|
| `correlation_id` (unique) | the call |
| `operation`, `status` | `decision`/`judge`/`web_context`, `succeeded`/`failed` |
| `model`, `connection`, `run_id` | |
| `judge_id`, `judge_revision`, `session_id`, `user_label` | |
| `http_status`, `error_code` | failures |
| `input_tokens`, `output_tokens`, `input_tokens_charged`, `credits_charged`, `billing_mode` | cost |
| `billing_uncertain` | the call failed in a way that may still have been billed (timeouts) |
| `latency_ms`, `attempts` | |
| `context` (JSON) | your local context |
| `payload` (JSON) | raw response, only with `usage.store_payloads` |
| `occurred_at` (UTC) | |

The request state is never stored.

```php
use Sysborg\LaravelJevai\Facades\JevUsage;

JevUsage::lastDays(7)->byModel()->get();          // list<UsageSummary>, one per model
JevUsage::today()->total()->inputTokensCharged;    // single total
JevUsage::period('30d')->byDay()->whereUser('42')->get();

$summary->calls; $summary->failures; $summary->errorRate();
$summary->inputTokensCharged; $summary->creditsCharged; $summary->averageLatencyMs();
$summary->uncertainBillings;
```

Groupings: `byModel`, `byConnection`, `byOperation`, `byUser`, `bySession`, `byDay`. Filters:
`whereModel`, `whereConnection`, `whereOperation`, `whereUser`, `whereSession`.

The `JevRun` Eloquent model is available for custom queries. Schedule pruning:

```php
Schedule::command('jev:prune')->daily();
```
