<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Pulse;
use Livewire\Livewire;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use Psr\Log\LoggerInterface;
use Sysborg\LaravelJevai\Adapters\Console\BalanceCommand;
use Sysborg\LaravelJevai\Adapters\Console\ModelsCommand;
use Sysborg\LaravelJevai\Adapters\Console\PruneCommand;
use Sysborg\LaravelJevai\Adapters\Console\UsageCommand;
use Sysborg\LaravelJevai\Adapters\Database\EloquentUsageRepository;
use Sysborg\LaravelJevai\Adapters\Jev\ConnectionRegistry;
use Sysborg\LaravelJevai\Adapters\Jev\GatewayFactory;
use Sysborg\LaravelJevai\Adapters\Jev\JevConnection;
use Sysborg\LaravelJevai\Adapters\Laravel\CacheCounterStore;
use Sysborg\LaravelJevai\Adapters\Laravel\CacheDebouncer;
use Sysborg\LaravelJevai\Adapters\Laravel\LaravelDecisionQueue;
use Sysborg\LaravelJevai\Adapters\Laravel\LaravelEventPublisher;
use Sysborg\LaravelJevai\Adapters\Laravel\Settings;
use Sysborg\LaravelJevai\Adapters\Log\LogTracer;
use Sysborg\LaravelJevai\Adapters\Null\CompositeMetricsRecorder;
use Sysborg\LaravelJevai\Adapters\Null\NullMetricsRecorder;
use Sysborg\LaravelJevai\Adapters\Null\NullTracer;
use Sysborg\LaravelJevai\Adapters\Null\NullUsageRepository;
use Sysborg\LaravelJevai\Adapters\OpenTelemetry\OtelMetricsRecorder;
use Sysborg\LaravelJevai\Adapters\OpenTelemetry\OtelTracer;
use Sysborg\LaravelJevai\Adapters\Pulse\JevUsageCard;
use Sysborg\LaravelJevai\Adapters\Pulse\PulseMetricsRecorder;
use Sysborg\LaravelJevai\Adapters\System\SystemClock;
use Sysborg\LaravelJevai\Adapters\System\UuidV7IdGenerator;
use Sysborg\LaravelJevai\Application\JevClient;
use Sysborg\LaravelJevai\Application\Pipeline\Pipeline;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Alert;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Budget;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Correlate;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Emit;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Log;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Measure;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Protect;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Record;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Redact;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Retry;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Trace;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Validate;
use Sysborg\LaravelJevai\Application\Support\Redactor;
use Sysborg\LaravelJevai\Application\Support\RequestValidator;
use Sysborg\LaravelJevai\Application\Support\RetryPolicy;
use Sysborg\LaravelJevai\Application\Support\SafeEventPublisher;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Ports\Driven\AccountGateway;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\CounterStore;
use Sysborg\LaravelJevai\Ports\Driven\Debouncer;
use Sysborg\LaravelJevai\Ports\Driven\DecisionGateway;
use Sysborg\LaravelJevai\Ports\Driven\DecisionQueue;
use Sysborg\LaravelJevai\Ports\Driven\EventPublisher;
use Sysborg\LaravelJevai\Ports\Driven\IdGenerator;
use Sysborg\LaravelJevai\Ports\Driven\MetricsRecorder;
use Sysborg\LaravelJevai\Ports\Driven\Tracer;
use Sysborg\LaravelJevai\Ports\Driven\UsageRepository;
use Sysborg\LaravelJevai\Ports\Driven\WebContextGateway;
use Sysborg\LaravelJevai\Ports\Driving\Jev;

/**
 * Composition root: registers the configuration and wires ports, adapters and the application layer.
 */
final class JevServiceProvider extends ServiceProvider
{
    /** Container key of the package logger. */
    public const string LOGGER = 'jev.logger';

    /**
     * Merge the package defaults into the `jev` config key and bind everything.
     *
     * Values in the application's published `config/jev.php` take precedence.
     * Everything is resolved lazily, so a missing API key never breaks boot.
     *
     * Example:
     * ```php
     * app(Jev::class); // JevClient wired with the default connection
     * ```
     *
     * @return void Nothing; bindings are registered on the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/jev.php', 'jev');

        $this->registerAdapters();
        $this->registerApplication();
    }

    /**
     * Register the console commands and the publishable config and migration.
     *
     * Example:
     * ```bash
     * php artisan vendor:publish --tag=jev-config
     * php artisan vendor:publish --tag=jev-migrations
     * ```
     *
     * @return void Nothing; commands and publishable paths are registered.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'jev');
        $this->registerPulseCard();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/jev.php' => config_path('jev.php'),
        ], 'jev-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'jev-migrations');

        $this->commands([
            UsageCommand::class,
            BalanceCommand::class,
            ModelsCommand::class,
            PruneCommand::class,
        ]);
    }

    /**
     * Bind the driven ports to their adapters.
     *
     * Example:
     * ```php
     * $this->registerAdapters(); // Clock => SystemClock, DecisionGateway => HttpDecisionGateway, ...
     * ```
     *
     * @return void Nothing.
     */
    private function registerAdapters(): void
    {
        $this->app->singleton(Settings::class, fn (Container $app): Settings => new Settings($app->make(Config::class)));
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(IdGenerator::class, UuidV7IdGenerator::class);
        $this->app->singleton(Tracer::class, fn (Container $app): Tracer => self::tracer($app));
        $this->app->singleton(MetricsRecorder::class, fn (Container $app): MetricsRecorder => self::metrics($app));
        $this->app->singleton(UsageRepository::class, function (Container $app): UsageRepository {
            $settings = $app->make(Settings::class);

            return match ($driver = $settings->string('usage.driver', 'null')) {
                'null' => new NullUsageRepository,
                'database' => new EloquentUsageRepository(
                    $app->make(ConnectionResolverInterface::class),
                    $settings->nullableString('usage.connection'),
                    $settings->string('usage.table', 'jev_runs'),
                ),
                default => throw InvalidValue::because("config jev.usage.driver [{$driver}]", 'must be "null" or "database"'),
            };
        });
        $this->app->singleton(EventPublisher::class, LaravelEventPublisher::class);

        $this->app->singleton(self::LOGGER, function (Container $app): LoggerInterface {
            $logger = $app->make(LoggerInterface::class);
            $channel = $app->make(Settings::class)->nullableString('logging.channel');

            if ($channel !== null && method_exists($logger, 'channel')) {
                $channelLogger = $logger->channel($channel);

                return $channelLogger instanceof LoggerInterface ? $channelLogger : $logger;
            }

            return $logger;
        });

        $this->app->singleton(ConnectionRegistry::class, function (Container $app): ConnectionRegistry {
            $config = $app->make(Config::class);
            $connections = $config->get('jev.connections', []);
            $default = $config->get('jev.default', 'default');

            return new ConnectionRegistry(
                is_array($connections) ? $connections : [],
                is_string($default) ? $default : 'default',
                $app instanceof Application && $app->environment('testing'),
            );
        });

        $this->app->singleton(GatewayFactory::class);

        $this->app->bind(DecisionGateway::class, fn (Container $app) => $app->make(GatewayFactory::class)->decision());
        $this->app->bind(WebContextGateway::class, fn (Container $app) => $app->make(GatewayFactory::class)->webContext());
        $this->app->bind(AccountGateway::class, fn (Container $app) => $app->make(GatewayFactory::class)->account());

        $this->app->singleton(Debouncer::class, fn (Container $app): Debouncer => new CacheDebouncer(
            $app->make(CacheFactory::class)->store($app->make(Settings::class)->nullableString('alerts.cache_store')),
        ));

        $this->app->singleton(CounterStore::class, fn (Container $app): CounterStore => new CacheCounterStore(
            $app->make(CacheFactory::class)->store($app->make(Settings::class)->nullableString('limits.cache_store')),
        ));

        $this->app->singleton(DecisionQueue::class, fn (Container $app): DecisionQueue => new LaravelDecisionQueue(
            $app->make(BusDispatcher::class),
            $app->make(Settings::class)->nullableString('queue.connection'),
            $app->make(Settings::class)->nullableString('queue.queue'),
        ));
    }

    /**
     * Bind the application layer: policies, the pipeline and the client.
     *
     * Example:
     * ```php
     * $this->registerApplication(); // Pipeline, RetryPolicy, JevClient, Jev => JevClient
     * ```
     *
     * @return void Nothing.
     */
    private function registerApplication(): void
    {
        $this->app->singleton(SafeEventPublisher::class, fn (Container $app): SafeEventPublisher => new SafeEventPublisher(
            $app->make(EventPublisher::class),
            self::logger($app),
            $app->make(Settings::class)->bool('events.enabled', true),
        ));

        $this->app->singleton(RetryPolicy::class, function (Container $app): RetryPolicy {
            $settings = $app->make(Settings::class);

            return new RetryPolicy(
                maxAttempts: max(1, $settings->int('retry.max_attempts', 3)),
                maxElapsedMs: $settings->int('retry.max_elapsed_ms', 60_000),
                baseDelayMs: $settings->int('retry.base_delay_ms', 250),
                maxDelayMs: $settings->int('retry.max_delay_ms', 5_000),
                maxRetryAfterSeconds: $settings->int('retry.max_retry_after_seconds', 30),
                retryOnTimeout: $settings->bool('retry.on_timeout', false),
            );
        });

        $this->app->singleton(Redactor::class, fn (Container $app): Redactor => Redactor::fromSpec(
            $app->make(Settings::class)->string('redaction.state', 'truncate:200'),
            $app->make(Settings::class)->stringList('redaction.trace_deny'),
        ));

        $this->app->singleton(RequestValidator::class, fn (Container $app): RequestValidator => new RequestValidator(
            self::defaultModel($app->make(Settings::class)),
        ));

        $this->app->singleton(Pipeline::class, function (Container $app): Pipeline {
            $settings = $app->make(Settings::class);
            $clock = $app->make(Clock::class);
            $events = $app->make(SafeEventPublisher::class);
            $logger = self::logger($app);

            $stages = [
                new Correlate(
                    $app->make(IdGenerator::class),
                    $clock,
                    $settings->nullableString('correlation.trace_key'),
                    $settings->bool('correlation.session_fallback', true),
                    $settings->string('default', 'default'),
                    self::defaultModel($settings),
                ),
                new Validate($app->make(RequestValidator::class)),
                new Redact($app->make(Redactor::class)),
                ...($settings->bool('logging.calls', true) ? [new Log($logger, $clock)] : []),
                new Alert(
                    $events,
                    $clock,
                    $app->make(Debouncer::class),
                    $logger,
                    $settings->nullableInt('alerts.tokens_remaining_threshold'),
                    $settings->int('alerts.debounce_seconds', 3600),
                ),
                new Emit($events, $clock, $settings->bool('events.include_raw', false)),
                new Record(
                    $app->make(UsageRepository::class),
                    $clock,
                    $logger,
                    $settings->bool('usage.enabled', true),
                    $settings->bool('usage.store_payloads', false),
                ),
                new Measure($app->make(MetricsRecorder::class), $clock, $logger),
                new Budget(
                    $app->make(CounterStore::class),
                    $clock,
                    $logger,
                    $settings->nullableInt('limits.daily_input_tokens'),
                ),
                new Trace($app->make(Tracer::class)),
                new Retry(
                    $app->make(RetryPolicy::class),
                    $clock,
                    $events,
                    $settings->bool('logging.calls', true) ? $logger : null,
                ),
                new Protect(
                    $app->make(CounterStore::class),
                    $clock,
                    $events,
                    $logger,
                    $settings->nullableInt('limits.rate_limit_per_minute'),
                    $settings->bool('limits.rate_limit_block', false),
                    $settings->int('limits.rate_limit_max_wait_seconds', 10),
                    $settings->nullableInt('limits.circuit_breaker_failures'),
                    $settings->int('limits.circuit_breaker_cooldown_seconds', 30),
                ),
            ];

            return new Pipeline(...$stages);
        });

        $this->app->singleton(JevClient::class);
        $this->app->alias(JevClient::class, Jev::class);
    }

    /**
     * Register the Jev Pulse card (`<livewire:jev.usage />`) when Pulse and Livewire are installed.
     *
     * Example:
     * ```blade
     * <livewire:jev.usage cols="6" />
     * ```
     *
     * @return void Nothing.
     */
    private function registerPulseCard(): void
    {
        if (class_exists(Pulse::class) && class_exists(Livewire::class) && $this->app->bound('livewire')) {
            Livewire::component('jev.usage', JevUsageCard::class);
        }
    }

    /**
     * The configured tracer (`jev.observability.tracing`), falling back to none when its package is missing.
     *
     * Example:
     * ```php
     * self::tracer($app); // OtelTracer when JEV_AI_TRACING=otel and open-telemetry/api is installed
     * ```
     *
     * @param  Container  $app  The container.
     * @return Tracer The tracer.
     *
     * @throws InvalidValue When the driver is unknown.
     */
    private static function tracer(Container $app): Tracer
    {
        $driver = strtolower($app->make(Settings::class)->string('observability.tracing', 'null'));

        return match ($driver) {
            'null' => new NullTracer,
            'log' => new LogTracer(self::logger($app), $app->make(Clock::class)),
            'otel' => interface_exists(TracerInterface::class)
                ? new OtelTracer(Globals::tracerProvider()->getTracer(OtelTracer::INSTRUMENTATION))
                : self::missing($app, 'open-telemetry/api', 'tracing', new NullTracer),
            default => throw InvalidValue::because("config jev.observability.tracing [{$driver}]", 'must be null, log or otel'),
        };
    }

    /**
     * The configured metrics recorders (`jev.observability.metrics`, comma separated),
     * skipping drivers whose package is missing.
     *
     * Example:
     * ```php
     * self::metrics($app); // CompositeMetricsRecorder(otel, pulse) for JEV_AI_METRICS=otel,pulse
     * ```
     *
     * @param  Container  $app  The container.
     * @return MetricsRecorder The recorder.
     *
     * @throws InvalidValue When a driver is unknown.
     */
    private static function metrics(Container $app): MetricsRecorder
    {
        $recorders = [];

        foreach ($app->make(Settings::class)->stringList('observability.metrics') as $driver) {
            $recorder = match ($driver = strtolower($driver)) {
                'null' => null,
                'otel' => interface_exists(MeterInterface::class)
                    ? new OtelMetricsRecorder(Globals::meterProvider()->getMeter(OtelTracer::INSTRUMENTATION))
                    : self::missing($app, 'open-telemetry/api', 'OpenTelemetry metrics', null),
                'pulse' => class_exists(Pulse::class)
                    ? new PulseMetricsRecorder($app->make(Pulse::class))
                    : self::missing($app, 'laravel/pulse', 'Pulse metrics', null),
                default => throw InvalidValue::because("config jev.observability.metrics [{$driver}]", 'must be null, otel or pulse'),
            };

            if ($recorder !== null) {
                $recorders[] = $recorder;
            }
        }

        return match (count($recorders)) {
            0 => new NullMetricsRecorder,
            1 => $recorders[0],
            default => new CompositeMetricsRecorder(...$recorders),
        };
    }

    /**
     * Log once that an optional package is missing and return the fallback.
     *
     * Example:
     * ```php
     * self::missing($app, 'laravel/pulse', 'Pulse metrics', null);
     * ```
     *
     * @template TFallback
     *
     * @param  Container  $app  The container.
     * @param  string  $package  The missing Composer package.
     * @param  string  $feature  What is disabled.
     * @param  TFallback  $fallback  What to use instead.
     * @return TFallback The fallback.
     */
    private static function missing(Container $app, string $package, string $feature, mixed $fallback): mixed
    {
        self::logger($app)->notice("Jev {$feature} is disabled: run `composer require {$package}` to enable it.");

        return $fallback;
    }

    /**
     * The default model of the default connection, read without validating the connection.
     *
     * Example:
     * ```php
     * self::defaultModel($settings); // 'jev-latest'
     * ```
     *
     * @param  Settings  $settings  The package settings.
     * @return string The model name.
     */
    private static function defaultModel(Settings $settings): string
    {
        $default = $settings->string('default', 'default');

        return $settings->string("connections.{$default}.model", JevConnection::DEFAULT_MODEL);
    }

    /**
     * The package logger (the configured channel, or the default one).
     *
     * Example:
     * ```php
     * self::logger($app)->warning('...');
     * ```
     *
     * @param  Container  $app  The container.
     * @return LoggerInterface The logger.
     */
    private static function logger(Container $app): LoggerInterface
    {
        $logger = $app->make(self::LOGGER);

        return $logger instanceof LoggerInterface ? $logger : $app->make(LoggerInterface::class);
    }
}
