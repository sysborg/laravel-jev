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

## Documentation rules

Every method, whatever its visibility (public, protected, private, constructors, abstract,
interface and enum methods), has a docblock with:

- a one-line summary, plus extra detail when the behaviour is not obvious;
- an `Example:` block with a short code snippet;
- `@param` for each parameter, **with a description**;
- `@return` **with a description** (not on constructors);
- `@throws` for each exception the method can raise, saying when.

```php
/**
 * Get a choice answer.
 *
 * Example:
 * ```php
 * $answers->choice('department')->choice; // 'billing'
 * ```
 *
 * @param  string  $questionId  The question id.
 * @return ChoiceAnswer The answer.
 *
 * @throws AnswerNotFound When Jev did not answer that question.
 * @throws AnswerTypeMismatch When the answer is not a choice answer.
 */
```

Pint's Laravel preset removes `@param`/`@return` tags that only repeat the type, so always
include a description.

Classes, interfaces and enums that belong to the **public API** carry `@api` in their class
docblock (facades, the driving port, builders, `JevFake` and the Domain). Changing them is a
breaking change and must be listed under **Breaking** in `CHANGELOG.md`. Internal helpers carry
`@internal`.

User documentation lives in `docs/en` and `docs/pt-BR`: update both when behaviour changes.

## Pull requests

- One task per PR when possible; reference its ID in the title, e.g. `feat(domain): question value objects [D-01]`.
- Add tests for every change and keep `composer check` green.
- Add an entry to the `Unreleased` section of `CHANGELOG.md`.
- Never commit real API keys. Tests use `JEV_AI_API_KEY=test-key`.
