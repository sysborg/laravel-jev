# laravel-jevai

> **Status: pre-release.** Under active development towards an open beta. Not ready for use yet.

Laravel package for the [Jev AI decision API](https://jev-ai.pro/docs), built with ports & adapters,
with first-class observability: lifecycle events, token usage and billing tracking, tracing and metrics.

## Requirements

- PHP 8.3+
- Laravel 11, 12 or 13

## Installation

```bash
composer require sysborg/laravel-jevai
```

```dotenv
JEV_AI_API_KEY=your-key
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=jev-config
```

## Roadmap

See [docs/open-beta-tasks.md](docs/open-beta-tasks.md).

## License

MIT. See [LICENSE](LICENSE).
