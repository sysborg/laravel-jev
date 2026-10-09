# Billing, retries and limits

## How Jev bills

- **Output tokens are free.** Each call draws on **either** paid input tokens (multiplied by the
  model rate: Jev 1×, Laya 2×, Clef 6×…) **or** credits, never both.
- If paid tokens cannot cover a call, the whole call is charged in credits (`credits-fallback`),
  and vice versa (`tokens-fallback`).
- Failed runs (402, 422, 502/504…) are refunded or not charged.
- A web search costs 170,000 input tokens.

Each result exposes this as `billing` (`mode`, `inputTokensCharged`, `creditsCharged`,
`tokensRemaining`), read from the response body and the `X-Jev-*` headers. `usage->cost` stays
`null`: Jev does not return it.

## What is retried

Jev has **no idempotency key**, so the package only retries failures that were certainly not billed:

| Failure | Retried | How |
|---|---|---|
| 429 rate limited | yes | waits exactly `Retry-After`; fails fast if it is longer than `retry.max_retry_after_seconds` (30) |
| 502 / 503 / 504 | yes | exponential backoff with jitter (250 ms, 500 ms, 1 s… capped at 5 s) |
| timeout / dropped connection | **no** (default) | the call may have been executed and billed |
| 400, 401, 402, 404, 409, 422, other | never | retrying cannot help |

Every call is bounded by `retry.max_attempts` (3) and `retry.max_elapsed_ms` (60 s). Each retry
dispatches `RetryScheduled` and logs a warning.

### Timeouts

A timeout raises `TransportFailure` with `mayHaveBeenBilled() === true`, and the usage record is
flagged `billing_uncertain`. Enable `JEV_AI_RETRY_ON_TIMEOUT=true` only if paying twice for some
calls is acceptable.

Queued decisions never retry at the job level either (`$tries = 1`).

## Client-side limits

All off by default; counters are shared through the cache (use Redis or another shared store with
several servers). If the cache fails, calls are let through and a warning is logged.

### Rate limiter

```dotenv
JEV_AI_RATE_LIMIT_PER_MINUTE=900     # Jev allows 1000 per account
JEV_AI_RATE_LIMIT_BLOCK=false        # true: wait for the next minute (at most 10 s)
```

Counts every HTTP attempt, retries included. Above the limit: `LocallyRateLimited` (with
`retryAfterSeconds`), nothing is sent.

### Circuit breaker

```dotenv
JEV_AI_CIRCUIT_BREAKER_FAILURES=5
JEV_AI_CIRCUIT_BREAKER_COOLDOWN=30
```

After 5 consecutive 502/503/504 or timeouts, attempts fail fast with `CircuitOpen` for 30 s
(`CircuitOpened` event). After the cooldown, calls go through again; the first success dispatches
`CircuitClosed`. Client errors do not count, and any success resets the count.

### Daily budget

```dotenv
JEV_AI_DAILY_INPUT_TOKENS=5000000
```

Charged input tokens are counted per connection and UTC day. Once the limit is reached, calls throw
`BudgetExceeded` without being sent. Calls already running still complete, so the budget can be
slightly exceeded.

### Low-balance alert

```dotenv
JEV_AI_LOW_BALANCE_TOKENS=100000
```

When a response reports fewer paid input tokens left, `BalanceLow` is dispatched (at most once per
hour and connection). A 402 always dispatches `CreditsExhausted`.
