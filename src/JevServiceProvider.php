<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Sysborg\LaravelJevai\Adapters\Jev\ConnectionRegistry;
use Sysborg\LaravelJevai\Adapters\Jev\GatewayFactory;
use Sysborg\LaravelJevai\Adapters\Jev\JevConnection;
use Sysborg\LaravelJevai\Adapters\Laravel\CacheDebouncer;
use Sysborg\LaravelJevai\Adapters\Laravel\LaravelDecisionQueue;
use Sysborg\LaravelJevai\Adapters\Laravel\LaravelEventPublisher;
use Sysborg\LaravelJevai\Adapters\Laravel\Settings;
use Sysborg\LaravelJevai\Adapters\Null\NullMetricsRecorder;
use Sysborg\LaravelJevai\Adapters\Null\NullTracer;
use Sysborg\LaravelJevai\Adapters\Null\NullUsageRepository;
use Sysborg\LaravelJevai\Adapters\System\SystemClock;
use Sysborg\LaravelJevai\Adapters\System\UuidV7IdGenerator;
use Sysborg\LaravelJevai\Application\JevClient;
use Sysborg\LaravelJevai\Application\Pipeline\Pipeline;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Alert;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Correlate;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Emit;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Measure;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Record;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Redact;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Retry;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Trace;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Validate;
use Sysborg\LaravelJevai\Application\Support\Redactor;
use Sysborg\LaravelJevai\Application\Support\RequestValidator;
use Sysborg\LaravelJevai\Application\Support\RetryPolicy;
use Sysborg\LaravelJevai\Application\Support\SafeEventPublisher;
use Sysborg\LaravelJevai\Ports\Driven\AccountGateway;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
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
     * Make the config publishable when running in the console.
     *
     * Example:
     * ```bash
     * php artisan vendor:publish --tag=jev-config
     * ```
     *
     * @return void Nothing; publishable paths are registered.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/jev.php' => config_path('jev.php'),
            ], 'jev-config');
        }
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
        $this->app->singleton(Tracer::class, NullTracer::class);
        $this->app->singleton(MetricsRecorder::class, NullMetricsRecorder::class);
        $this->app->singleton(UsageRepository::class, NullUsageRepository::class);
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

            return new Pipeline(
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
                new Trace($app->make(Tracer::class)),
                new Retry($app->make(RetryPolicy::class), $clock, $events),
            );
        });

        $this->app->singleton(JevClient::class);
        $this->app->alias(JevClient::class, Jev::class);
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
