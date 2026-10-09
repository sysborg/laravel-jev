# Contributing

Thanks for helping improve `sysborg/laravel-jevai`.

## Setup

```bash
composer install
composer check   # Pint (check), Larastan, Pest
```

Useful scripts:

| Command | What it does |
|---|---|
| `composer test` | Run the Pest suite (architecture, unit, feature) |
| `composer analyse` | Larastan at level max |
| `composer format` | Fix code style with Pint |
| `composer check` | Everything CI runs |

## Architecture rules

The package follows ports & adapters. The rules below are enforced by `tests/Architecture`:

- `src/Domain` is plain PHP and does not depend on Laravel or any other layer.
- `src/Ports` contains only interfaces and depends only on `Domain`.
- `src/Application` depends only on `Domain` and `Ports`.
- `src/Adapters` holds every framework or vendor integration (HTTP, events, Eloquent,
  OpenTelemetry, Pulse, queue, console).

See [docs/open-beta-tasks.md](docs/open-beta-tasks.md) for the roadmap.

## Pull requests

- One task per PR when possible; reference its ID in the title, e.g. `feat(domain): question value objects [D-01]`.
- Add tests for every change and keep `composer check` green.
- Add an entry to the `Unreleased` section of `CHANGELOG.md`.
- Never commit real API keys. Tests use `JEV_AI_API_KEY=test-key`.
