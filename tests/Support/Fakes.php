<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Tests\Support;

use Closure;
use DateTimeImmutable;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Sysborg\LaravelJevai\Domain\Answer\AnswerSet;
use Sysborg\LaravelJevai\Domain\Answer\ChoiceAnswer;
use Sysborg\LaravelJevai\Domain\Answer\NoulAnswer;
use Sysborg\LaravelJevai\Domain\Answer\ScoreAnswer;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Events\JevEvent;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Question\ChoiceQuestion;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Run\RunMetadata;
use Sysborg\LaravelJevai\Domain\Usage\Billing;
use Sysborg\LaravelJevai\Domain\Usage\BillingMode;
use Sysborg\LaravelJevai\Domain\Usage\RunRecord;
use Sysborg\LaravelJevai\Domain\Usage\Usage;
use Sysborg\LaravelJevai\Domain\Usage\UsageQuery;
use Sysborg\LaravelJevai\Domain\Usage\UsageSummary;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextLatency;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextUsage;
use Sysborg\LaravelJevai\Ports\Driven\CounterStore;
use Sysborg\LaravelJevai\Ports\Driven\Debouncer;
use Sysborg\LaravelJevai\Ports\Driven\DecisionGateway;
use Sysborg\LaravelJevai\Ports\Driven\DecisionQueue;
use Sysborg\LaravelJevai\Ports\Driven\EventPublisher;
use Sysborg\LaravelJevai\Ports\Driven\IdGenerator;
use Sysborg\LaravelJevai\Ports\Driven\MetricsRecorder;
use Sysborg\LaravelJevai\Ports\Driven\Span;
use Sysborg\LaravelJevai\Ports\Driven\Tracer;
use Sysborg\LaravelJevai\Ports\Driven\UsageRepository;
use Sysborg\LaravelJevai\Ports\Driven\WebContextGateway;
use Throwable;

/**
 * A successful decision result for the given request, as a gateway would return it.
 *
 * @param  DecisionRequest  $request  The request; one answer is produced per question.
 * @param  CorrelationId  $id  Correlation id stamped on the metadata.
 * @return DecisionResult The result (120 input tokens, 8 output, billed in tokens).
 */
function fakeDecisionResult(DecisionRequest $request, CorrelationId $id): DecisionResult
{
    $answers = [];

    foreach ($request->questions ?? [] as $questionId => $question) {
        $answers[] = match (true) {
            $question instanceof NoulQuestion => new NoulAnswer($questionId, 0.9),
            $question instanceof ChoiceQuestion => new ChoiceAnswer($questionId, $question->options()[0], 0.9),
            default => new ScoreAnswer($questionId, 1.0),
        };
    }

    if ($request->judge !== null) {
        $answers[] = new NoulAnswer('verdict', 0.2);
    }

    return new DecisionResult(
        new AnswerSet(...$answers),
        new Usage(120, 8),
        new Billing(BillingMode::Tokens, 120, 0.0, 9_880),
        new RunMetadata($id, $request->model ?? 'jev-latest', 400, runId: 'run-1', connection: 'default'),
        ['answers' => ['raw' => true]],
    );
}

/**
 * Decision gateway returning scripted outcomes, one per attempt.
 */
final class FakeDecisionGateway implements DecisionGateway
{
    /** @var list<array{request: DecisionRequest, correlationId: CorrelationId}> */
    public array $calls = [];

    /** @var list<JevException|Closure(DecisionRequest, CorrelationId): DecisionResult|null> */
    private array $outcomes;

    /**
     * @param  JevException|Closure(DecisionRequest, CorrelationId): DecisionResult|null  ...$outcomes  Per attempt:
     *                                                                                                  an exception to throw, a result factory, or null for a default success.
     */
    public function __construct(JevException|Closure|null ...$outcomes)
    {
        $this->outcomes = array_values($outcomes);
    }

    /**
     * Record the attempt and play the next scripted outcome (default success when exhausted).
     *
     * @param  DecisionRequest  $request  The request sent.
     * @param  CorrelationId  $correlationId  The correlation id.
     * @return DecisionResult The scripted result.
     *
     * @throws JevException When the outcome is an exception.
     */
    public function decide(DecisionRequest $request, CorrelationId $correlationId): DecisionResult
    {
        $this->calls[] = ['request' => $request, 'correlationId' => $correlationId];
        $outcome = array_shift($this->outcomes);

        if ($outcome instanceof JevException) {
            throw $outcome;
        }

        return $outcome === null ? fakeDecisionResult($request, $correlationId) : $outcome($request, $correlationId);
    }
}

/**
 * Web-context gateway returning scripted outcomes, one per attempt.
 */
final class FakeWebContextGateway implements WebContextGateway
{
    /** @var list<array{request: WebContextRequest, correlationId: CorrelationId}> */
    public array $calls = [];

    /** @var list<JevException|null> */
    private array $outcomes;

    /**
     * @param  JevException|null  ...$outcomes  Per attempt: an exception to throw, or null for a success.
     */
    public function __construct(?JevException ...$outcomes)
    {
        $this->outcomes = array_values($outcomes);
    }

    /**
     * Record the attempt and play the next scripted outcome.
     *
     * @param  WebContextRequest  $request  The request sent.
     * @param  CorrelationId  $correlationId  The correlation id.
     * @return WebContextResult A "yes" result with 2140 input tokens.
     *
     * @throws JevException When the outcome is an exception.
     */
    public function resolve(WebContextRequest $request, CorrelationId $correlationId): WebContextResult
    {
        $this->calls[] = ['request' => $request, 'correlationId' => $correlationId];
        $outcome = array_shift($this->outcomes);

        if ($outcome !== null) {
            throw $outcome;
        }

        return new WebContextResult(
            'yes', 0.88,
            new ChoiceAnswer('with_web', 'yes'), new ChoiceAnswer('without_web', 'no'),
            [], null,
            new WebContextUsage(2140, 12, 2, true), new WebContextLatency(560, 410),
            new RunMetadata($correlationId, 'jev-latest', 980, connection: 'default'),
            new Billing(BillingMode::Tokens, 172_140),
            ['decision' => 'yes'],
        );
    }
}

/**
 * Collects published events; can be told to throw like a broken listener.
 */
final class RecordingEventPublisher implements EventPublisher
{
    /** @var list<JevEvent> */
    public array $events = [];

    public bool $fail = false;

    /**
     * @param  JevEvent  $event  The event.
     * @return void Nothing.
     *
     * @throws RuntimeException When `$fail` is true.
     */
    public function publish(JevEvent $event): void
    {
        if ($this->fail) {
            throw new RuntimeException('Listener exploded.');
        }

        $this->events[] = $event;
    }

    /**
     * @return list<string> Names of the published events, in order.
     */
    public function names(): array
    {
        return array_map(fn (JevEvent $event): string => $event->name(), $this->events);
    }

    /**
     * @template T of JevEvent
     *
     * @param  class-string<T>  $class  Event class.
     * @return list<T> Published events of that class.
     */
    public function of(string $class): array
    {
        return array_values(array_filter($this->events, fn (JevEvent $event): bool => $event instanceof $class));
    }
}

/**
 * Keeps usage records in memory; can be told to throw like a broken database.
 */
final class InMemoryUsageRepository implements UsageRepository
{
    /** @var list<RunRecord> */
    public array $records = [];

    public bool $fail = false;

    /**
     * @param  RunRecord  $record  The record.
     * @return void Nothing.
     *
     * @throws RuntimeException When `$fail` is true.
     */
    public function record(RunRecord $record): void
    {
        if ($this->fail) {
            throw new RuntimeException('Database down.');
        }

        $this->records[] = $record;
    }

    /**
     * @param  UsageQuery  $query  Ignored.
     * @return list<UsageSummary> Always empty.
     */
    public function summarize(UsageQuery $query): array
    {
        return [];
    }

    /**
     * @param  DateTimeImmutable  $before  Ignored.
     * @return int Always 0.
     */
    public function prune(DateTimeImmutable $before): int
    {
        return 0;
    }
}

/**
 * Span collecting its attributes and exceptions.
 */
final class RecordingSpan implements Span
{
    /** @var array<string, string|int|float|bool|null> */
    public array $attributes = [];

    /** @var list<Throwable> */
    public array $exceptions = [];

    /**
     * @param  array<string, string|int|float|bool|null>  $attributes  Initial attributes.
     */
    public function __construct(public readonly string $name, array $attributes)
    {
        $this->attributes = $attributes;
    }

    /**
     * @param  string  $key  Attribute name.
     * @param  string|int|float|bool|null  $value  Attribute value.
     * @return void Nothing.
     */
    public function setAttribute(string $key, string|int|float|bool|null $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $attributes  Attributes.
     * @return void Nothing.
     */
    public function setAttributes(array $attributes): void
    {
        $this->attributes = [...$this->attributes, ...$attributes];
    }

    /**
     * @param  Throwable  $exception  The failure.
     * @return void Nothing.
     */
    public function recordException(Throwable $exception): void
    {
        $this->exceptions[] = $exception;
    }
}

/**
 * Tracer collecting finished spans.
 */
final class RecordingTracer implements Tracer
{
    /** @var list<RecordingSpan> */
    public array $spans = [];

    /**
     * @template TReturn
     *
     * @param  string  $name  Span name.
     * @param  array<string, string|int|float|bool|null>  $attributes  Initial attributes.
     * @param  callable(Span): TReturn  $callback  The work.
     * @return TReturn The callback result.
     *
     * @throws Throwable Whatever the callback throws, after recording it.
     */
    public function span(string $name, array $attributes, callable $callback): mixed
    {
        $span = new RecordingSpan($name, $attributes);
        $this->spans[] = $span;

        try {
            return $callback($span);
        } catch (Throwable $e) {
            $span->recordException($e);

            throw $e;
        }
    }
}

/**
 * Metrics recorder collecting counters and histograms; can be told to throw.
 */
final class RecordingMetrics implements MetricsRecorder
{
    /** @var list<array{type: string, name: string, value: int|float, attributes: array<string, mixed>}> */
    public array $points = [];

    public bool $fail = false;

    /**
     * @param  string  $name  Metric name.
     * @param  int|float  $value  Amount.
     * @param  array<string, string|int|float|bool|null>  $attributes  Dimensions.
     * @return void Nothing.
     *
     * @throws RuntimeException When `$fail` is true.
     */
    public function increment(string $name, int|float $value = 1, array $attributes = []): void
    {
        $this->add('counter', $name, $value, $attributes);
    }

    /**
     * @param  string  $name  Metric name.
     * @param  int|float  $value  Observation.
     * @param  array<string, string|int|float|bool|null>  $attributes  Dimensions.
     * @return void Nothing.
     *
     * @throws RuntimeException When `$fail` is true.
     */
    public function histogram(string $name, int|float $value, array $attributes = []): void
    {
        $this->add('histogram', $name, $value, $attributes);
    }

    /**
     * @param  string  $name  Metric name.
     * @return int|float Sum of all values recorded under that name.
     */
    public function total(string $name): int|float
    {
        return array_sum(array_column(array_filter($this->points, fn (array $p): bool => $p['name'] === $name), 'value'));
    }

    /**
     * @param  string  $type  counter or histogram.
     * @param  string  $name  Metric name.
     * @param  int|float  $value  Value.
     * @param  array<string, string|int|float|bool|null>  $attributes  Dimensions.
     * @return void Nothing.
     *
     * @throws RuntimeException When `$fail` is true.
     */
    private function add(string $type, string $name, int|float $value, array $attributes): void
    {
        if ($this->fail) {
            throw new RuntimeException('Metrics backend down.');
        }

        $this->points[] = ['type' => $type, 'name' => $name, 'value' => $value, 'attributes' => $attributes];
    }
}

/**
 * Predictable correlation ids: corr-1, corr-2, ...
 */
final class SequenceIdGenerator implements IdGenerator
{
    private int $next = 1;

    /**
     * @return CorrelationId The next id.
     */
    public function correlationId(): CorrelationId
    {
        return new CorrelationId('corr-'.$this->next++);
    }
}

/**
 * Decision queue collecting pushed requests.
 */
final class RecordingDecisionQueue implements DecisionQueue
{
    /** @var list<array{request: DecisionRequest, correlationId: CorrelationId}> */
    public array $pushed = [];

    /**
     * @param  DecisionRequest  $request  The request.
     * @param  CorrelationId  $correlationId  The id.
     * @return void Nothing.
     */
    public function push(DecisionRequest $request, CorrelationId $correlationId): void
    {
        $this->pushed[] = ['request' => $request, 'correlationId' => $correlationId];
    }
}

/**
 * Debouncer keeping claimed keys in memory (the window never expires); can be told to throw.
 */
final class InMemoryDebouncer implements Debouncer
{
    /** @var array<string, int> */
    public array $claimed = [];

    public bool $fail = false;

    /**
     * @param  string  $key  What is being debounced.
     * @param  int  $seconds  Window length, recorded.
     * @return bool True only the first time a key is claimed.
     *
     * @throws RuntimeException When `$fail` is true.
     */
    public function attempt(string $key, int $seconds): bool
    {
        if ($this->fail) {
            throw new RuntimeException('Cache down.');
        }

        if (isset($this->claimed[$key])) {
            return false;
        }

        $this->claimed[$key] = $seconds;

        return true;
    }
}

/**
 * Counter store in memory (lifetimes are recorded, never enforced); can be told to throw.
 */
final class InMemoryCounterStore implements CounterStore
{
    /** @var array<string, int> */
    public array $values = [];

    public bool $fail = false;

    /**
     * @param  string  $key  Counter key.
     * @param  int  $by  Amount.
     * @param  int  $ttlSeconds  Ignored.
     * @return int The new value.
     *
     * @throws RuntimeException When `$fail` is true.
     */
    public function increment(string $key, int $by, int $ttlSeconds): int
    {
        $this->guard();

        return $this->values[$key] = ($this->values[$key] ?? 0) + $by;
    }

    /**
     * @param  string  $key  Counter key.
     * @return int The value, 0 when missing.
     *
     * @throws RuntimeException When `$fail` is true.
     */
    public function get(string $key): int
    {
        $this->guard();

        return $this->values[$key] ?? 0;
    }

    /**
     * @param  string  $key  Counter key.
     * @param  int  $value  The value.
     * @param  int  $ttlSeconds  Ignored.
     * @return void Nothing.
     *
     * @throws RuntimeException When `$fail` is true.
     */
    public function put(string $key, int $value, int $ttlSeconds): void
    {
        $this->guard();
        $this->values[$key] = $value;
    }

    /**
     * @param  string  $key  Counter key.
     * @return void Nothing.
     *
     * @throws RuntimeException When `$fail` is true.
     */
    public function forget(string $key): void
    {
        $this->guard();
        unset($this->values[$key]);
    }

    /**
     * @return void Nothing.
     *
     * @throws RuntimeException When `$fail` is true.
     */
    private function guard(): void
    {
        if ($this->fail) {
            throw new RuntimeException('Cache down.');
        }
    }
}

/**
 * PSR-3 logger keeping records in memory.
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param  mixed  $level  Log level.
     * @param  string|Stringable  $message  Message.
     * @param  array<array-key, mixed>  $context  Context.
     * @return void Nothing.
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
