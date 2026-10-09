# Configuration

Publish the file with `php artisan vendor:publish --tag=jev-config`. Every value can also be set
through the environment variables below.

## Connection

| Key | Env | Default | Notes |
|---|---|---|---|
| `default` | `JEV_AI_CONNECTION` | `default` | Connection used by the package |
| `connections.{name}.api_key` | `JEV_AI_API_KEY` | — | **Required.** Only read from config/env, never logged |
| `connections.{name}.base_url` | `JEV_AI_BASE_URL` | `https://jev-ai.pro/api` | Must be `https://` (plain `http://` only in `testing`) |
| `connections.{name}.model` | `JEV_AI_MODEL` | `jev-latest` | Used when a request names no model |
| `connections.{name}.timeout.connect` | `JEV_AI_CONNECT_TIMEOUT` | `5` | Seconds |
| `connections.{name}.timeout.request` | `JEV_AI_REQUEST_TIMEOUT` | `30` | Seconds |

Connections are validated on first use, so a missing key never breaks application boot. The
package always uses the `default` connection; choosing a connection per tenant or account is up to
your platform (the adapter-level `GatewayFactory::decision('name')` builds gateways for any
configured connection).

## Retries

| Key | Env | Default |
|---|---|---|
| `retry.max_attempts` | `JEV_AI_RETRY_MAX_ATTEMPTS` | `3` (1 disables retries) |
| `retry.max_elapsed_ms` | `JEV_AI_RETRY_MAX_ELAPSED_MS` | `60000` |
| `retry.base_delay_ms` / `retry.max_delay_ms` | — | `250` / `5000` |
| `retry.max_retry_after_seconds` | — | `30` (longer `Retry-After` fails fast) |
| `retry.on_timeout` | `JEV_AI_RETRY_ON_TIMEOUT` | `false` (see [billing](billing-and-retries.md)) |

## Client-side limits (all off by default)

| Key | Env | Default |
|---|---|---|
| `limits.cache_store` | `JEV_AI_LIMITS_CACHE_STORE` | default store |
| `limits.rate_limit_per_minute` | `JEV_AI_RATE_LIMIT_PER_MINUTE` | off |
| `limits.rate_limit_block` | `JEV_AI_RATE_LIMIT_BLOCK` | `false` |
| `limits.rate_limit_max_wait_seconds` | — | `10` |
| `limits.circuit_breaker_failures` | `JEV_AI_CIRCUIT_BREAKER_FAILURES` | off |
| `limits.circuit_breaker_cooldown_seconds` | `JEV_AI_CIRCUIT_BREAKER_COOLDOWN` | `30` |
| `limits.daily_input_tokens` | `JEV_AI_DAILY_INPUT_TOKENS` | off |

## Correlation and redaction

| Key | Env | Default |
|---|---|---|
| `correlation.trace_key` | — | `correlation_id` (null: not sent to Jev) |
| `correlation.session_fallback` | — | `true` (correlation id becomes `session_id` when none is set) |
| `redaction.state` | `JEV_AI_REDACT_STATE` | `truncate:200` (`none`, `truncate:N`, `hash`, `omit`) |
| `redaction.trace_deny` | `JEV_AI_TRACE_DENY` | empty (e.g. `email,phone`) |

## Events and alerts

| Key | Env | Default |
|---|---|---|
| `events.enabled` | `JEV_AI_EVENTS` | `true` |
| `events.include_raw` | — | `false` (raw response body in success events) |
| `alerts.tokens_remaining_threshold` | `JEV_AI_LOW_BALANCE_TOKENS` | off |
| `alerts.debounce_seconds` | — | `3600` |
| `alerts.cache_store` | `JEV_AI_CACHE_STORE` | default store |

## Usage records

| Key | Env | Default |
|---|---|---|
| `usage.enabled` | — | `true` |
| `usage.driver` | `JEV_AI_USAGE_DRIVER` | `null` (`database` stores records) |
| `usage.connection` | `JEV_AI_USAGE_DB_CONNECTION` | default DB connection |
| `usage.table` | — | `jev_runs` |
| `usage.store_payloads` | — | `false` (raw response bodies, may hold personal data) |
| `usage.retention_days` | `JEV_AI_USAGE_RETENTION_DAYS` | `90` (`jev:prune`) |

## Queue, logs, tracing, metrics

| Key | Env | Default |
|---|---|---|
| `queue.connection` / `queue.queue` | `JEV_AI_QUEUE_CONNECTION` / `JEV_AI_QUEUE` | defaults |
| `logging.channel` | `JEV_AI_LOG_CHANNEL` | default channel |
| `logging.calls` | `JEV_AI_LOG_CALLS` | `true` |
| `observability.tracing` | `JEV_AI_TRACING` | `null` (`log`, `otel`) |
| `observability.metrics` | `JEV_AI_METRICS` | `null` (`otel`, `pulse`, or `otel,pulse`) |

A tracing or metrics driver whose package is missing falls back to no-op with a log notice.
Unknown values throw `InvalidValue` when first used.
