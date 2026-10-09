# Open beta

`sysborg/laravel-jevai` is in **open beta** (`0.x`).

## Stability

- The **public API** is everything marked `@api` in the source: the `Jev` and `JevUsage` facades,
  the `Ports\Driving\Jev` contract, the builders, `JevFake`, and the `Domain` value objects, events
  and exceptions. Changes to it follow SemVer for `0.x`: **minor** releases may break it, and every
  break is listed under **Breaking** in the [CHANGELOG](../../CHANGELOG.md).
- Everything else (pipeline stages, adapters, `@internal` classes) may change in any release.
- Config keys may be renamed in minor releases (listed in the CHANGELOG).

## Known limitations

- The response shape of `GET /v1/models`, saved-judge calls, and own web-context sources are not
  documented by Jev: the package accepts the plausible shapes and tolerates unknown fields.
- `X-Jev-Run-Id` is optional; `run_id` is null when Jev does not send it.
- After a circuit-breaker cooldown, all calls are let through (no single-probe half-open state).
- The daily budget can be slightly exceeded by calls already in flight.
- Jev error messages are kept (truncated) and could echo request content.

## Reporting issues

- Bugs and ideas: [GitHub issues](https://github.com/sysborg/laravel-jev/issues). Include the
  package version, Laravel/PHP versions and, if possible, the correlation id of the call.
- Security: privately, see [SECURITY.md](../../SECURITY.md).
