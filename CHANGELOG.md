# Changelog

All notable changes to `sysborg/laravel-jevai` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the package is in `0.x` (open beta), minor releases may contain breaking changes;
every one of them is listed under a **Breaking** heading.

## [Unreleased]

### Breaking
- Config moved to `jev.default` and `jev.connections.{name}` (`api_key`, `base_url`, `model`,
  `timeout`). Republish `config/jev.php` if you published it before.

### Added
- Project foundation: Composer package, service provider with publishable `config/jev.php`,
  ports & adapters directory layout, Pest + Testbench, Larastan (level max), Pint,
  architecture tests and GitHub Actions CI matrix.
- Domain layer: question and answer value objects, `QuestionSet` / `AnswerSet`, `DecisionRequest`
  (inline questions or saved judge), `State`, `Trace`, `Context`, `JudgeRef`, `DecisionResult`,
  `Usage`, `Billing` / `BillingMode`, `CorrelationId`, `RunMetadata`, web-context request/result
  types, and the `JevException` hierarchy with retry and billing semantics.
- Ports: driven interfaces (`DecisionGateway`, `WebContextGateway`, `AccountGateway`, `EventPublisher`,
  `UsageRepository`, `Tracer` / `Span`, `MetricsRecorder`, `Clock`, `IdGenerator`) and the driving
  `Jev` contract, plus the domain types they need (`Balance`, `JevEvent`, `PendingDecision`,
  `RunRecord`, `UsageQuery`, `UsageSummary`).
- Jev HTTP adapter: `HttpDecisionGateway`, `HttpWebContextGateway`, `HttpAccountGateway` on Laravel's
  HTTP client, with error mapping, header/billing mapping, strict-but-tolerant response parsing,
  per-connection configuration (`ConnectionRegistry`, `GatewayFactory`), a leak-proof `ApiKey`
  and `SystemClock`.
- Application layer: `JevClient` (the `Jev` facade), immutable fluent builders, use cases, and the
  call pipeline `Correlate → Validate → Redact → Emit → Record → Measure → Trace → Retry` with
  UUIDv7 correlation ids, body-size validation, state/trace redaction, retry policy (429 Retry-After,
  5xx backoff, no timeout retries by default), and isolated side effects.
- Events: `DecisionRequested`, `DecisionSucceeded`, `DecisionFailed`, `RetryScheduled`,
  `WebContextResolved`, `TokenUsageRecorded`, dispatched through Laravel's event dispatcher.
- Queued decisions: `Jev::queue()` / `->queue()` dispatch `EvaluateDecisionJob` (single try);
  results arrive through events with the returned correlation id.
- Config sections `retry`, `correlation`, `redaction`, `events`, `usage`, `queue`, `logging`.
- Alert events: `BalanceLow` (threshold on `X-Jev-Tokens-Remaining`, debounced through the cache,
  config `jev.alerts.*`), `CreditsExhausted` (402) and `JudgeRulesChanged` (409), published by a new
  `Alert` pipeline stage after the lifecycle events.
- Every event now carries the connection name and the model (requested or connection default).
- Usage and cost accounting: `jev_runs` migration (`--tag=jev-migrations`), database usage repository
  (`jev.usage.driver = database`), `JevRun` model, `JevUsage` reports (`lastDays(7)->byModel()->get()`,
  `today()->total()`), and the `jev:usage`, `jev:balance`, `jev:models` and `jev:prune` commands.
- Minimum Laravel 11 version raised to 11.45.
- Observability: `LogTracer` and `OtelTracer` (`JEV_AI_TRACING=log|otel`), `OtelMetricsRecorder` and
  `PulseMetricsRecorder` (`JEV_AI_METRICS=otel,pulse`), a Jev Pulse card (`<livewire:jev.usage />`),
  and structured call logs with the correlation id (`JEV_AI_LOG_CALLS`). Missing optional packages
  fall back to no-op drivers with a notice.
