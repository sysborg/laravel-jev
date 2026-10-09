# Security

## The API key

- Read only from config/env (`JEV_AI_API_KEY`) and wrapped in `ApiKey` as soon as the package
  receives it. The raw value is not even a property of the object, so `var_dump()`, `dd()`,
  `print_r()`, `var_export()`, JSON and `(array)` casts all print `[redacted]`.
- `ApiKey` refuses `serialize()` and `clone`: it can never end up in a queued job or a cache.
  Queued jobs carry the request and the correlation id only; the key is resolved when the job runs.
- The constructor parameter is `#[SensitiveParameter]`, so it is hidden from stack traces.
- The base URL must be `https://` (plain `http://` is only accepted in the `testing` environment).

An end-to-end test runs success, retry, 401, timeout and queue scenarios with a canary key and
checks logs, spans, events, exceptions, usage rows, the serialized job and object dumps.

Outside the package's control, as with any secret: Laravel's config repository holds the env value,
and Laravel's HTTP client events (`RequestSending`) expose the `Authorization` header to your
listeners. Do not log those events verbatim.

## What leaves your app

| Data | Sent to Jev | Events | Logs | Spans | Usage table |
|---|---|---|---|---|---|
| state | yes | redacted preview | redacted preview | never | never |
| questions / judge id | yes | ids only | — | — | judge id |
| `trace` | yes (minus `trace_deny` keys) | — | — | — | — |
| `context` | **never** | yes | — | — | yes |
| answers / raw body | — | answers (raw opt-in) | — | — | opt-in (`store_payloads`) |
| `user`, `session_id` | yes | yes | — | — | yes |

## Redaction

```dotenv
JEV_AI_REDACT_STATE=truncate:200   # none | truncate:N | hash | omit
JEV_AI_TRACE_DENY=email,phone      # trace fields never sent to Jev
```

- `truncate:N` keeps the first N characters, `hash` keeps a `sha256:` digest, `omit` keeps nothing.
- `none` shows the full state in events and logs: only use it where that is acceptable.
- Validation messages name the field, never its value.

Known limitation: Jev's own error messages are kept (truncated to 500 characters) in exceptions,
`DecisionFailed` and logs. If Jev ever echoes request content in an error message, it would appear
there.

## Reporting vulnerabilities

See [SECURITY.md](../../SECURITY.md). Please do not open public issues for security problems.
