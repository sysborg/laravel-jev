# Security Policy

## Supported versions

During the open beta only the latest `0.x` release receives security fixes.

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security problems.

Report them privately through
[GitHub Security Advisories](https://github.com/sysborg/laravel-jev/security/advisories/new)
or by email to **andmarruda@gmail.com**.

Include:

- the affected version,
- a description of the issue and its impact,
- steps to reproduce or a proof of concept.

You will get an acknowledgement within 3 business days. Once a fix is released, the
advisory will be published and you will be credited unless you prefer otherwise.

## Scope

Of particular interest:

- leaks of the Jev API key through logs, events, exceptions, traces, or queued jobs,
- request `state` or `trace` data escaping configured redaction,
- behaviour that can cause unexpected billing (e.g. unsafe retries).
