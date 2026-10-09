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
    | One record per call for token and cost accounting. Until the database
    | repository ships, records are discarded. `store_payloads` also keeps
    | the raw response body (may contain personal data).
    |
    */

    'usage' => [
        'enabled' => true,
        'store_payloads' => false,
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
    | Log channel for package warnings (failing listeners, storage or metric
    | errors). Null uses the application's default channel.
    |
    */

    'logging' => [
        'channel' => env('JEV_AI_LOG_CHANNEL'),
    ],

];
