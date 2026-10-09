<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Sysborg\LaravelJevai\Adapters\Queue\EvaluateDecisionJob;
use Sysborg\LaravelJevai\Application\JevClient;
use Sysborg\LaravelJevai\Application\Support\RequestValidator;
use Sysborg\LaravelJevai\Application\UseCases\EvaluateDecision;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\Trace;
use Sysborg\LaravelJevai\Domain\Events\BalanceLow;
use Sysborg\LaravelJevai\Domain\Events\CreditsExhausted;
use Sysborg\LaravelJevai\Domain\Events\DecisionFailed;
use Sysborg\LaravelJevai\Domain\Events\DecisionRequested;
use Sysborg\LaravelJevai\Domain\Events\DecisionSucceeded;
use Sysborg\LaravelJevai\Domain\Events\RetryScheduled;
use Sysborg\LaravelJevai\Domain\Events\TokenUsageRecorded;
use Sysborg\LaravelJevai\Domain\Exceptions\InsufficientCredits;
use Sysborg\LaravelJevai\Domain\Exceptions\Unauthorized;
use Sysborg\LaravelJevai\Domain\Question\ChoiceQuestion;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Facades\Jev;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\DecisionGateway;
use Sysborg\LaravelJevai\Ports\Driving\Jev as JevContract;
use Sysborg\LaravelJevai\Tests\Support\FakeClock;

beforeEach(function () {
    Http::preventStrayRequests();
    app()->instance(Clock::class, new FakeClock(stepMs: 50));
});

it('resolves the facade and the contract to the same client', function () {
    expect(Jev::getFacadeRoot())->toBeInstanceOf(JevClient::class)
        ->and(app(JevContract::class))->toBe(Jev::getFacadeRoot());
});

it('evaluates end to end and gets the response back through Laravel events', function () {
    Event::fake();
    Http::fake(['*' => Http::response(jevFixture('decision-success'), 200, ['X-Jev-Run-Id' => 'run_1'])]);

    $result = Jev::model('jev-latest')
        ->state('My card was charged twice!')
        ->noul('is_urgent', 'Does this convey urgency?')
        ->choice('department', 'Which team?', ['billing' => 'Payments', 'technical' => 'Bugs', 'sales' => 'Pricing'])
        ->score('frustration', 'How frustrated?', ['Calm', 'Frustrated', 'Very angry'])
        ->context('ticket_id', 42)
        ->evaluate();

    $correlationId = (string) $result->meta->correlationId;

    expect($result->choice('department')->choice)->toBe('billing')
        ->and($result->meta->attempts)->toBe(1)
        ->and($result->meta->runId)->toBe('run_1')
        ->and($correlationId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/');

    Http::assertSent(function (Request $request) use ($correlationId): bool {
        $body = json_decode($request->body(), true);

        return $body['trace'] === ['correlation_id' => $correlationId]
            && $body['session_id'] === $correlationId
            && ! array_key_exists('context', $body);
    });

    Event::assertDispatched(DecisionRequested::class, fn (DecisionRequested $e) => (string) $e->correlationId() === $correlationId
        && $e->context()->get('ticket_id') === 42);
    Event::assertDispatched(DecisionSucceeded::class, fn (DecisionSucceeded $e) => $e->result->choice('department')->choice === 'billing'
        && $e->context()->get('ticket_id') === 42
        && $e->result->raw === null);
    Event::assertDispatched(TokenUsageRecorded::class, fn (TokenUsageRecorded $e) => $e->usage->inputTokens === 120
        && $e->billing->inputTokensCharged === 120);
});

it('retries a 503 and reports the failure of a 401 through events', function () {
    Event::fake();
    Http::fakeSequence()
        ->push(jevFixture('error'), 503)
        ->push(jevFixture('decision-success'))
        ->push(jevFixture('error'), 401);

    $result = Jev::state('text')->noul('is_urgent', 'Urgent?')->evaluate();

    expect($result->meta->attempts)->toBe(2)
        ->and(fn () => Jev::state('text')->noul('is_urgent', 'Urgent?')->evaluate())->toThrow(Unauthorized::class);

    Http::assertSentCount(3);
    Event::assertDispatched(RetryScheduled::class, 1);
    Event::assertDispatched(DecisionFailed::class, fn (DecisionFailed $e) => $e->exception === Unauthorized::class && $e->httpStatus === 401);
});

it('never lets a failing listener break the call', function () {
    Http::fake(['*' => Http::response(jevFixture('decision-success'))]);
    Event::listen(DecisionSucceeded::class, fn () => throw new RuntimeException('listener bug'));

    expect(Jev::state('text')->noul('is_urgent', 'Urgent?')->evaluate()->noul('is_urgent')->noul)->toBe(0.95);
});

it('can switch events off', function () {
    config()->set('jev.events.enabled', false);
    Event::fake([DecisionRequested::class, DecisionSucceeded::class, TokenUsageRecorded::class, DecisionFailed::class]);
    Http::fake(['*' => Http::response(jevFixture('decision-success'))]);

    Jev::state('text')->noul('is_urgent', 'Urgent?')->evaluate();

    Event::assertNothingDispatched();
});

describe('alerts', function () {
    it('dispatches BalanceLow once per window through the cache debouncer', function () {
        config()->set('jev.alerts.tokens_remaining_threshold', '10000');
        config()->set('cache.default', 'array');
        Event::fake();
        Http::fake(['*' => Http::response(jevFixture('decision-success'), 200, ['X-Jev-Tokens-Remaining' => '9880'])]);

        Jev::state('text')->noul('is_urgent', 'Urgent?')->evaluate();
        Jev::state('text')->noul('is_urgent', 'Urgent?')->evaluate();

        Event::assertDispatchedTimes(BalanceLow::class, 1);
        Event::assertDispatched(BalanceLow::class, fn (BalanceLow $e) => $e->tokensRemaining === 9880
            && $e->threshold === 10000
            && $e->connection === 'default');
    });

    it('dispatches CreditsExhausted when Jev answers 402', function () {
        Event::fake();
        Http::fake(['*' => Http::response(jevFixture('error'), 402)]);

        expect(fn () => Jev::state('text')->noul('is_urgent', 'Urgent?')->evaluate())->toThrow(InsufficientCredits::class);

        Event::assertDispatched(CreditsExhausted::class, fn (CreditsExhausted $e) => $e->errorCode === 'jev_error'
            && $e->connection === 'default'
            && $e->model === 'jev-latest');
    });
});

describe('queue', function () {
    it('dispatches a job carrying the request and the pending correlation id', function () {
        Bus::fake();

        $pending = Jev::state('text')->noul('is_urgent', 'Urgent?')->context('ticket_id', 42)->queue();

        Bus::assertDispatched(EvaluateDecisionJob::class, fn (EvaluateDecisionJob $job) => $job->correlationId->equals($pending->correlationId)
            && $job->request->context->get('ticket_id') === 42
            && $job->tries === 1);
    });

    it('uses the configured queue connection and name', function () {
        config()->set('jev.queue', ['connection' => 'redis', 'queue' => 'jev']);
        Bus::fake();

        Jev::state('text')->noul('is_urgent', 'Urgent?')->queue();

        Bus::assertDispatched(EvaluateDecisionJob::class, fn (EvaluateDecisionJob $job) => $job->connection === 'redis' && $job->queue === 'jev');
    });

    it('delivers the result through events with the pending correlation id', function () {
        Event::fake();
        Http::fake(['*' => Http::response(jevFixture('decision-success'))]);
        $id = new CorrelationId('pending-123');

        (new EvaluateDecisionJob(DecisionRequest::forQuestions('text', QuestionSet::of(new NoulQuestion('is_urgent', 'Urgent?'))), $id))
            ->handle(app(EvaluateDecision::class));

        Event::assertDispatched(DecisionSucceeded::class, fn (DecisionSucceeded $e) => (string) $e->correlationId() === 'pending-123');
        Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['trace'] === ['correlation_id' => 'pending-123']);
    });

    it('swallows Jev failures so the job is never retried, after reporting them', function () {
        Event::fake();
        Http::fake(['*' => Http::response(jevFixture('error'), 402)]);

        (new EvaluateDecisionJob(DecisionRequest::forQuestions('text', QuestionSet::of(new NoulQuestion('q', 'Q?'))), new CorrelationId('p-1')))
            ->handle(app(EvaluateDecision::class));

        Event::assertDispatched(DecisionFailed::class, fn (DecisionFailed $e) => $e->httpStatus === 402);
    });

    it('never serializes the API key into the job', function () {
        config()->set('jev.connections.default.api_key', 'sk-live-never-in-a-job');
        Bus::fake();

        Jev::state('text')->noul('q', 'Q?')->queue();

        Bus::assertDispatched(EvaluateDecisionJob::class, fn (EvaluateDecisionJob $job) => ! str_contains(serialize($job), 'sk-live-never-in-a-job'));
    });
});

it('estimates exactly the body the HTTP adapter sends', function () {
    Http::fake(['*' => Http::response(jevFixture('decision-success'))]);
    $request = DecisionRequest::forQuestions(
        ['subject' => 'Refund é', 'url' => 'https://example.com/a/b'],
        QuestionSet::of(new ChoiceQuestion('1', 'Which tier?', ['1' => 'Low', '2' => 'High'])),
    )->withModel('jev-latest')->withSessionId('s-1')->withUser('u-1')->withTrace(Trace::of(['correlation_id' => 'c-1']));

    app(DecisionGateway::class)->decide($request, new CorrelationId('c-1'));

    Http::assertSent(fn (Request $sent) => strlen($sent->body()) === app(RequestValidator::class)->estimateBytes($request));
});
