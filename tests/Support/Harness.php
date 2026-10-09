<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Tests\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Sysborg\LaravelJevai\Application\JevClient;
use Sysborg\LaravelJevai\Application\Pipeline\Pipeline;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Alert;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Correlate;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Emit;
use Sysborg\LaravelJevai\Application\Pipeline\Stages\Log;
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
use Sysborg\LaravelJevai\Application\UseCases\EvaluateDecision;
use Sysborg\LaravelJevai\Application\UseCases\GetBalance;
use Sysborg\LaravelJevai\Application\UseCases\ListModels;
use Sysborg\LaravelJevai\Application\UseCases\ResolveWebContext;
use Sysborg\LaravelJevai\Domain\Account\Balance;
use Sysborg\LaravelJevai\Ports\Driven\AccountGateway;

/**
 * The real application layer wired to in-memory fakes.
 */
final class Harness
{
    public FakeDecisionGateway $decisions;

    public FakeWebContextGateway $webContext;

    public RecordingEventPublisher $events;

    public InMemoryUsageRepository $usage;

    public RecordingTracer $tracer;

    public RecordingMetrics $metrics;

    public RecordingDecisionQueue $queue;

    public ArrayLogger $logger;

    public InMemoryDebouncer $debouncer;

    public FakeClock $clock;

    public JevClient $client;

    /**
     * @param  array{
     *     decisions?: FakeDecisionGateway,
     *     webContext?: FakeWebContextGateway,
     *     policy?: RetryPolicy,
     *     redactor?: Redactor,
     *     eventsEnabled?: bool,
     *     includeRaw?: bool,
     *     storePayloads?: bool,
     *     traceKey?: string|null,
     *     sessionFallback?: bool,
     *     maxBodyBytes?: int,
     *     lowBalanceTokens?: int|null,
     *     logCalls?: bool,
     * }  $options  Overrides of the defaults.
     */
    public function __construct(array $options = [])
    {
        $this->decisions = $options['decisions'] ?? new FakeDecisionGateway;
        $this->webContext = $options['webContext'] ?? new FakeWebContextGateway;
        $this->events = new RecordingEventPublisher;
        $this->usage = new InMemoryUsageRepository;
        $this->tracer = new RecordingTracer;
        $this->metrics = new RecordingMetrics;
        $this->queue = new RecordingDecisionQueue;
        $this->logger = new ArrayLogger;
        $this->debouncer = new InMemoryDebouncer;
        $this->clock = new FakeClock(stepMs: 100);

        $ids = new SequenceIdGenerator;
        $validator = new RequestValidator('jev-latest', $options['maxBodyBytes'] ?? RequestValidator::MAX_BODY_BYTES);
        $events = new SafeEventPublisher($this->events, $this->logger, $options['eventsEnabled'] ?? true);
        $policy = $options['policy'] ?? new RetryPolicy(random: new Randomizer(new Mt19937(42)));

        $logCalls = $options['logCalls'] ?? false;

        $pipeline = new Pipeline(...[
            new Correlate(
                $ids,
                $this->clock,
                array_key_exists('traceKey', $options) ? $options['traceKey'] : 'correlation_id',
                $options['sessionFallback'] ?? true,
                'default',
                'jev-latest',
            ),
            new Validate($validator),
            new Redact($options['redactor'] ?? new Redactor),
            ...($logCalls ? [new Log($this->logger, $this->clock)] : []),
            new Alert($events, $this->clock, $this->debouncer, $this->logger, $options['lowBalanceTokens'] ?? null, 3600),
            new Emit($events, $this->clock, $options['includeRaw'] ?? false),
            new Record($this->usage, $this->clock, $this->logger, true, $options['storePayloads'] ?? false),
            new Measure($this->metrics, $this->clock, $this->logger),
            new Trace($this->tracer),
            new Retry($policy, $this->clock, $events, $logCalls ? $this->logger : null),
        ]);

        $account = new class implements AccountGateway
        {
            /** @return list<string> */
            public function models(): array
            {
                return ['jev-latest', 'clef'];
            }

            public function balance(): Balance
            {
                return new Balance(42.0, 1_000);
            }
        };

        $this->client = new JevClient(
            new EvaluateDecision($this->decisions, $pipeline),
            new ResolveWebContext($this->webContext, $pipeline),
            new ListModels($account),
            new GetBalance($account),
            $this->queue,
            $validator,
            $ids,
            $this->clock,
        );
    }
}
