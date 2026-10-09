<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default connection
    |--------------------------------------------------------------------------
    |
    | The connection used when none is given, e.g. Jev::decide($request).
    | Use Jev::connection('name') to pick another one.
    |
    */

    'default' => env('JEV_AI_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Each connection has its own API key, base URL, default model and
    | timeouts (seconds), e.g. one per tenant or per Jev account.
    |
    | The API key is read only from the environment and is never logged,
    | dispatched in events, or serialized into queued jobs. The base URL
    | must use https:// outside the testing environment.
    |
    */

    'connections' => [

        'default' => [
            'api_key' => env('JEV_AI_API_KEY'),
            'base_url' => env('JEV_AI_BASE_URL', 'https://jev-ai.pro/api'),
            'model' => env('JEV_AI_MODEL', 'jev-latest'),
            'timeout' => [
                'connect' => (int) env('JEV_AI_CONNECT_TIMEOUT', 5),
                'request' => (int) env('JEV_AI_REQUEST_TIMEOUT', 30),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Jev has no idempotency key, so only failures that were certainly not
    | billed are retried: 429 (honoring Retry-After) and 502/503/504.
    | Timeouts and dropped connections may have been billed and are NOT
    | retried unless `on_timeout` is enabled.
    |
    */

    'retry' => [
        'max_attempts' => (int) env('JEV_AI_RETRY_MAX_ATTEMPTS', 3),
        'max_elapsed_ms' => (int) env('JEV_AI_RETRY_MAX_ELAPSED_MS', 60_000),
        'base_delay_ms' => 250,
        'max_delay_ms' => 5_000,
        'max_retry_after_seconds' => 30,
        'on_timeout' => (bool) env('JEV_AI_RETRY_ON_TIMEOUT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Client-side limits
    |--------------------------------------------------------------------------
    |
    | All off by default. Counters live in the cache store `cache_store`
    | (null = default); use a shared store (Redis...) with several servers.
    | If the store fails, calls are let through with a warning.
    |
    | `rate_limit_per_minute`: HTTP attempts per minute and connection (Jev
    | allows 1000 per account). Above it, attempts throw LocallyRateLimited,
    | or wait for the next minute when `rate_limit_block` is on (at most
    | `rate_limit_max_wait_seconds`).
    |
    | `circuit_breaker_failures`: consecutive 502/503/504 or timeouts that
    | open the circuit; calls then fail fast with CircuitOpen for
    | `circuit_breaker_cooldown_seconds` (CircuitOpened / CircuitClosed events).
    |
    | `daily_input_tokens`: charged input tokens allowed per UTC day and
    | connection; beyond it calls throw BudgetExceeded without being sent.
    |
    */

    'limits' => [
        'cache_store' => env('JEV_AI_LIMITS_CACHE_STORE'),
        'rate_limit_per_minute' => env('JEV_AI_RATE_LIMIT_PER_MINUTE'),
        'rate_limit_block' => (bool) env('JEV_AI_RATE_LIMIT_BLOCK', false),
        'rate_limit_max_wait_seconds' => 10,
        'circuit_breaker_failures' => env('JEV_AI_CIRCUIT_BREAKER_FAILURES'),
        'circuit_breaker_cooldown_seconds' => (int) env('JEV_AI_CIRCUIT_BREAKER_COOLDOWN', 30),
        'daily_input_tokens' => env('JEV_AI_DAILY_INPUT_TOKENS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Correlation
    |--------------------------------------------------------------------------
    |
    | Every call gets a UUIDv7 correlation id, carried by all its events,
    | records and spans. It is also sent in Jev's private `trace` under
    | `trace_key` (null disables this) and, when `session_fallback` is on,
    | used as `session_id` when the request has none.
    |
    */

    'correlation' => [
        'trace_key' => 'correlation_id',
        'session_fallback' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | `state`: how the request state appears in events, logs and spans:
    | "none" (as is), "truncate:N", "hash" (sha256) or "omit".
    | `trace_deny`: trace fields never sent to Jev (e.g. "email,phone").
    |
    */

    'redaction' => [
        'state' => env('JEV_AI_REDACT_STATE', 'truncate:200'),
        'trace_deny' => env('JEV_AI_TRACE_DENY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Lifecycle events (DecisionRequested, DecisionSucceeded, DecisionFailed,
    | RetryScheduled, WebContextResolved, TokenUsageRecorded) are dispatched
    | through Laravel's event dispatcher. A failing listener never breaks the
    | call. `include_raw` keeps the raw response body in success events.
    |
    */

    'events' => [
        'enabled' => (bool) env('JEV_AI_EVENTS', true),
        'include_raw' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | BalanceLow is dispatched when a response reports fewer paid input tokens
    | than `tokens_remaining_threshold` (null disables it), at most once per
    | `debounce_seconds` and connection. The debounce uses the cache store
    | `cache_store` (null = default); use a shared store with several servers.
    | CreditsExhausted (402) and JudgeRulesChanged (409) are always dispatched.
    |
    */

    'alerts' => [
        'tokens_remaining_threshold' => env('JEV_AI_LOW_BALANCE_TOKENS'),
        'debounce_seconds' => 3600,
        'cache_store' => env('JEV_AI_CACHE_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage records
    |--------------------------------------------------------------------------
    |
    | One record per call (success or final failure) for token and cost
    | accounting. `driver`: "null" discards records, "database" stores them
    | in `table` on the DB `connection` (publish the migration first:
    | php artisan vendor:publish --tag=jev-migrations && php artisan migrate).
    | `store_payloads` also keeps the raw response body (may contain personal
    | data). `retention_days` is used by `php artisan jev:prune`.
    |
    */

    'usage' => [
        'enabled' => true,
        'driver' => env('JEV_AI_USAGE_DRIVER', 'null'),
        'connection' => env('JEV_AI_USAGE_DB_CONNECTION'),
        'table' => 'jev_runs',
        'store_payloads' => false,
        'retention_days' => (int) env('JEV_AI_USAGE_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Where Jev::queue() / ->queue() jobs go. Results arrive through events.
    |
    */

    'queue' => [
        'connection' => env('JEV_AI_QUEUE_CONNECTION'),
        'queue' => env('JEV_AI_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | `channel`: log channel for the package (null = default channel).
    | `calls`: structured call logs, always with the correlation id: debug
    | when a call starts and succeeds, warning for each retry, error when it
    | fails. Only the redacted state preview is ever logged.
    | Package warnings (failing listeners, storage or metric errors) are
    | always logged.
    |
    */

    'logging' => [
        'channel' => env('JEV_AI_LOG_CHANNEL'),
        'calls' => (bool) env('JEV_AI_LOG_CALLS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracing and metrics
    |--------------------------------------------------------------------------
    |
    | `tracing`: "null", "log" (spans written to the log channel at debug
    | level) or "otel" (OpenTelemetry; requires open-telemetry/api and an
    | SDK configured by your app). One span per call, retries included, with
    | GenAI semantic-convention attributes.
    |
    | `metrics`: comma separated list of "otel" and/or "pulse" (requires
    | laravel/pulse), or "null". Records jev.requests, jev.tokens.*,
    | jev.credits.charged, jev.latency and jev.attempts.
    |
    | A driver whose package is missing falls back to "null" with a notice.
    |
    */

    'observability' => [
        'tracing' => env('JEV_AI_TRACING', 'null'),
        'metrics' => env('JEV_AI_METRICS', 'null'),
    ],

];
