<?php

declare(strict_types=1);

use Random\Engine\Mt19937;
use Random\Randomizer;
use Sysborg\LaravelJevai\Application\Support\Redactor;
use Sysborg\LaravelJevai\Application\Support\RetryPolicy;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\JudgeRef;
use Sysborg\LaravelJevai\Domain\Decision\Trace;
use Sysborg\LaravelJevai\Domain\Events\DecisionFailed;
use Sysborg\LaravelJevai\Domain\Events\DecisionRequested;
use Sysborg\LaravelJevai\Domain\Events\DecisionSucceeded;
use Sysborg\LaravelJevai\Domain\Events\RetryScheduled;
use Sysborg\LaravelJevai\Domain\Events\TokenUsageRecorded;
use Sysborg\LaravelJevai\Domain\Events\WebContextResolved;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\RateLimited;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;
use Sysborg\LaravelJevai\Domain\Exceptions\Unauthorized;
use Sysborg\LaravelJevai\Domain\Exceptions\UpstreamFailure;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;
use Sysborg\LaravelJevai\Domain\Usage\RunStatus;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Tests\Support\FakeDecisionGateway;
use Sysborg\LaravelJevai\Tests\Support\FakeWebContextGateway;
use Sysborg\LaravelJevai\Tests\Support\Harness;

$request = fn (): DecisionRequest => DecisionRequest::forQuestions(
    'My card was charged twice!',
    QuestionSet::of(new NoulQuestion('is_urgent', 'Urgent?')),
)->withContext(Context::of(['ticket_id' => 42]));

$policy = fn (array $overrides = []): RetryPolicy => new RetryPolicy(...[
    'random' => new Randomizer(new Mt19937(42)),
    ...$overrides,
]);

describe('a successful call', function () use ($request) {
    it('correlates the request and stamps the final timing', function () use ($request) {
        $h = new Harness;

        $result = $h->client->decide($request());
        $sent = $h->decisions->calls[0]['request'];

        expect((string) $result->meta->correlationId)->toBe('corr-1')
            ->and($result->meta->attempts)->toBe(1)
            ->and($result->meta->latencyMs)->toBeGreaterThan(0)
            ->and((string) $h->decisions->calls[0]['correlationId'])->toBe('corr-1')
            ->and($sent->trace->get('correlation_id'))->toBe('corr-1')
            ->and($sent->sessionId)->toBe('corr-1');
    });

    it('keeps an explicit session id and can skip trace injection', function () use ($request) {
        $h = new Harness(['traceKey' => null, 'sessionFallback' => false]);

        $h->client->decide($request()->withSessionId('ticket-42'));
        $h->client->decide($request());

        expect($h->decisions->calls[0]['request']->sessionId)->toBe('ticket-42')
            ->and($h->decisions->calls[0]['request']->trace->isEmpty())->toBeTrue()
            ->and($h->decisions->calls[1]['request']->sessionId)->toBeNull();
    });

    it('emits requested, succeeded and token usage events, in order', function () use ($request) {
        $h = new Harness;

        $h->client->decide($request());

        expect($h->events->names())->toBe([
            'jev.decision.requested',
            'jev.decision.succeeded',
            'jev.usage.recorded',
        ]);

        $requested = $h->events->of(DecisionRequested::class)[0];
        $succeeded = $h->events->of(DecisionSucceeded::class)[0];
        $usage = $h->events->of(TokenUsageRecorded::class)[0];

        expect($requested->operation)->toBe(RunOperation::Decision)
            ->and($requested->questionIds)->toBe(['is_urgent'])
            ->and($requested->sessionId)->toBe('corr-1')
            ->and($requested->context()->get('ticket_id'))->toBe(42)
            ->and($requested->statePreview)->toBe('My card was charged twice!')
            ->and((string) $succeeded->correlationId())->toBe('corr-1')
            ->and($succeeded->result->noul('is_urgent')->noul)->toBe(0.9)
            ->and($succeeded->result->raw)->toBeNull()
            ->and($succeeded->context()->get('ticket_id'))->toBe(42)
            ->and($usage->usage->inputTokens)->toBe(120)
            ->and($usage->billing->inputTokensCharged)->toBe(120)
            ->and($usage->model)->toBe('jev-latest')
            ->and($usage->connection)->toBe('default');
    });

    it('keeps the raw body in the returned result, and in events only when configured', function () use ($request) {
        $without = new Harness;
        $with = new Harness(['includeRaw' => true]);

        expect($without->client->decide($request())->raw)->not->toBeNull()
            ->and($without->events->of(DecisionSucceeded::class)[0]->result->raw)->toBeNull();

        $with->client->decide($request());

        expect($with->events->of(DecisionSucceeded::class)[0]->result->raw)->not->toBeNull();
    });

    it('records one usage record', function () use ($request) {
        $h = new Harness;

        $h->client->decide($request()->withUser('user-7'));

        expect($h->usage->records)->toHaveCount(1)
            ->and($h->usage->records[0]->status)->toBe(RunStatus::Succeeded)
            ->and($h->usage->records[0]->operation)->toBe(RunOperation::Decision)
            ->and((string) $h->usage->records[0]->correlationId)->toBe('corr-1')
            ->and($h->usage->records[0]->usage->inputTokens)->toBe(120)
            ->and($h->usage->records[0]->user)->toBe('user-7')
            ->and($h->usage->records[0]->context->get('ticket_id'))->toBe(42)
            ->and($h->usage->records[0]->payload)->toBeNull();
    });

    it('stores the payload only when configured', function () use ($request) {
        $h = new Harness(['storePayloads' => true]);

        $h->client->decide($request());

        expect($h->usage->records[0]->payload)->toBe(['answers' => ['raw' => true]]);
    });

    it('records metrics', function () use ($request) {
        $h = new Harness;

        $h->client->decide($request());

        expect($h->metrics->total('jev.requests'))->toBe(1)
            ->and($h->metrics->total('jev.tokens.input'))->toBe(120)
            ->and($h->metrics->total('jev.tokens.output'))->toBe(8)
            ->and($h->metrics->total('jev.tokens.charged'))->toBe(120)
            ->and($h->metrics->total('jev.attempts'))->toBe(1)
            ->and($h->metrics->points[0]['attributes'])->toMatchArray(['status' => 'success', 'model' => 'jev-latest']);
    });

    it('traces the whole call in one span with GenAI attributes', function () use ($request) {
        $h = new Harness;

        $h->client->decide($request()->withModel('clef'));

        expect($h->tracer->spans)->toHaveCount(1)
            ->and($h->tracer->spans[0]->name)->toBe('jev.decision')
            ->and($h->tracer->spans[0]->attributes)->toMatchArray([
                'gen_ai.system' => 'jev',
                'gen_ai.operation.name' => 'decision',
                'gen_ai.request.model' => 'clef',
                'gen_ai.response.model' => 'clef',
                'gen_ai.usage.input_tokens' => 120,
                'gen_ai.usage.output_tokens' => 8,
                'jev.correlation_id' => 'corr-1',
                'jev.billing.mode' => 'tokens',
                'jev.run_id' => 'run-1',
                'jev.attempts' => 1,
            ]);
    });

    it('marks judge calls as such', function () {
        $h = new Harness;

        $h->client->decide(DecisionRequest::forJudge('transcript', new JudgeRef('judge_1', 2)));

        expect($h->events->of(DecisionRequested::class)[0]->judgeId)->toBe('judge_1')
            ->and($h->events->of(DecisionRequested::class)[0]->operation)->toBe(RunOperation::Judge)
            ->and($h->usage->records[0]->operation)->toBe(RunOperation::Judge)
            ->and($h->tracer->spans[0]->name)->toBe('jev.judge');
    });
});

describe('retries', function () use ($request, $policy) {
    it('retries upstream failures with backoff and emits RetryScheduled', function () use ($request) {
        $h = new Harness(['decisions' => new FakeDecisionGateway(new UpstreamFailure(503), null)]);

        $result = $h->client->decide($request());

        expect($result->meta->attempts)->toBe(2)
            ->and($h->decisions->calls)->toHaveCount(2)
            ->and($h->clock->sleeps)->toHaveCount(1)
            ->and($h->clock->sleeps[0])->toBeGreaterThanOrEqual(125)->toBeLessThanOrEqual(250)
            ->and($h->events->names())->toBe([
                'jev.decision.requested',
                'jev.retry.scheduled',
                'jev.decision.succeeded',
                'jev.usage.recorded',
            ])
            ->and($h->usage->records)->toHaveCount(1)
            ->and($h->tracer->spans)->toHaveCount(1);

        $retry = $h->events->of(RetryScheduled::class)[0];

        expect($retry->failedAttempt)->toBe(1)
            ->and($retry->httpStatus)->toBe(503)
            ->and($retry->reason)->toBe(UpstreamFailure::class)
            ->and($retry->wasRateLimited())->toBeFalse()
            ->and((string) $retry->correlationId())->toBe('corr-1');
    });

    it('waits exactly Retry-After on 429', function () use ($request) {
        $h = new Harness(['decisions' => new FakeDecisionGateway(new RateLimited(2), null)]);

        $h->client->decide($request());

        expect($h->clock->sleeps)->toBe([2000])
            ->and($h->events->of(RetryScheduled::class)[0]->wasRateLimited())->toBeTrue()
            ->and($h->events->of(RetryScheduled::class)[0]->retryAfterSeconds)->toBe(2);
    });

    it('fails fast when Retry-After exceeds the cap', function () use ($request) {
        $h = new Harness(['decisions' => new FakeDecisionGateway(new RateLimited(90))]);

        expect(fn () => $h->client->decide($request()))->toThrow(RateLimited::class)
            ->and($h->clock->sleeps)->toBe([]);
    });

    it('never retries client errors', function () use ($request) {
        $h = new Harness(['decisions' => new FakeDecisionGateway(new Unauthorized)]);

        expect(fn () => $h->client->decide($request()))->toThrow(Unauthorized::class)
            ->and($h->decisions->calls)->toHaveCount(1);
    });

    it('does not retry timeouts by default, because the call may have been billed', function () use ($request) {
        $h = new Harness(['decisions' => new FakeDecisionGateway(new TransportFailure)]);

        expect(fn () => $h->client->decide($request()))->toThrow(TransportFailure::class)
            ->and($h->decisions->calls)->toHaveCount(1);
    });

    it('retries timeouts when explicitly enabled', function () use ($request, $policy) {
        $h = new Harness([
            'decisions' => new FakeDecisionGateway(new TransportFailure, null),
            'policy' => $policy(['retryOnTimeout' => true]),
        ]);

        expect($h->client->decide($request())->meta->attempts)->toBe(2);
    });

    it('stops after the maximum number of attempts', function () use ($request) {
        $h = new Harness(['decisions' => new FakeDecisionGateway(new UpstreamFailure(502), new UpstreamFailure(502), new UpstreamFailure(502), null)]);

        expect(fn () => $h->client->decide($request()))->toThrow(UpstreamFailure::class)
            ->and($h->decisions->calls)->toHaveCount(3)
            ->and($h->events->of(RetryScheduled::class))->toHaveCount(2)
            ->and($h->events->of(DecisionFailed::class)[0]->attempts)->toBe(3);
    });

    it('stops when the next wait would exceed the time budget', function () use ($request, $policy) {
        $h = new Harness([
            'decisions' => new FakeDecisionGateway(new RateLimited(5), null),
            'policy' => $policy(['maxElapsedMs' => 3_000]),
        ]);

        expect(fn () => $h->client->decide($request()))->toThrow(RateLimited::class)
            ->and($h->decisions->calls)->toHaveCount(1);
    });
});

describe('a failed call', function () use ($request) {
    it('emits DecisionFailed, records the failure and rethrows', function () use ($request) {
        $h = new Harness(['decisions' => new FakeDecisionGateway(new TransportFailure)]);

        expect(fn () => $h->client->decide($request()))->toThrow(TransportFailure::class);

        $failed = $h->events->of(DecisionFailed::class)[0];

        expect($h->events->names())->toBe(['jev.decision.requested', 'jev.decision.failed'])
            ->and($failed->exception)->toBe(TransportFailure::class)
            ->and($failed->mayHaveBeenBilled)->toBeTrue()
            ->and($failed->retryable)->toBeFalse()
            ->and($failed->attempts)->toBe(1)
            ->and($failed->httpStatus)->toBeNull()
            ->and($failed->context()->get('ticket_id'))->toBe(42)
            ->and($h->usage->records[0]->status)->toBe(RunStatus::Failed)
            ->and($h->usage->records[0]->billingUncertain)->toBeTrue()
            ->and($h->metrics->points[0]['attributes'])->toMatchArray(['status' => 'error', 'error' => 'TransportFailure'])
            ->and($h->tracer->spans[0]->exceptions)->toHaveCount(1);
    });
});

describe('isolation of side effects', function () use ($request) {
    it('returns the result when a listener throws', function () use ($request) {
        $h = new Harness;
        $h->events->fail = true;

        expect($h->client->decide($request())->noul('is_urgent')->noul)->toBe(0.9)
            ->and($h->logger->records)->not->toBeEmpty()
            ->and($h->logger->records[0]['message'])->toContain('listener failed')
            ->and($h->logger->records[0]['context']['correlation_id'])->toBe('corr-1');
    });

    it('returns the result when storing usage or recording metrics fails', function () use ($request) {
        $h = new Harness;
        $h->usage->fail = true;
        $h->metrics->fail = true;

        expect($h->client->decide($request())->meta->attempts)->toBe(1)
            ->and(array_column($h->logger->records, 'message'))->toContain(
                'Storing Jev usage failed; the Jev call was not affected.',
                'Recording Jev metrics failed; the Jev call was not affected.',
            );
    });

    it('keeps recording usage when events are disabled', function () use ($request) {
        $h = new Harness(['eventsEnabled' => false]);

        $h->client->decide($request());

        expect($h->events->events)->toBe([])
            ->and($h->usage->records)->toHaveCount(1);
    });
});

describe('validation and redaction', function () use ($request) {
    it('rejects oversized bodies before anything is sent, emitted or recorded', function () {
        $h = new Harness(['maxBodyBytes' => 500]);

        expect(fn () => $h->client->decide(DecisionRequest::forQuestions(
            str_repeat('x', 600),
            QuestionSet::of(new NoulQuestion('q', 'Q?')),
        )))->toThrow(InvalidValue::class, 'at most 500 bytes')
            ->and($h->decisions->calls)->toBe([])
            ->and($h->events->events)->toBe([])
            ->and($h->usage->records)->toBe([]);
    });

    it('redacts the state preview', function (Redactor $redactor, ?string $expected) use ($request) {
        $h = new Harness(['redactor' => $redactor]);

        $h->client->decide($request());

        expect($h->events->of(DecisionRequested::class)[0]->statePreview)->toBe($expected);
    })->with([
        'truncate' => [new Redactor('truncate', 7), 'My card…'],
        'hash' => [new Redactor('hash'), 'sha256:'.hash('sha256', 'My card was charged twice!')],
        'omit' => [new Redactor('omit'), null],
    ]);

    it('removes denied trace fields before sending', function () use ($request) {
        $h = new Harness(['redactor' => new Redactor(traceDenyKeys: ['email'])]);

        $h->client->decide($request()->withTrace(Trace::of(['email' => 'a@b.c', 'ticket_id' => 42])));

        expect($h->decisions->calls[0]['request']->trace->toArray())
            ->toBe(['ticket_id' => 42, 'correlation_id' => 'corr-1']);
    });
});

describe('web context', function () {
    it('runs through the same pipeline', function () {
        $h = new Harness(['webContext' => new FakeWebContextGateway(new UpstreamFailure(503), null)]);

        $result = $h->client->resolveWebContext(
            WebContextRequest::ask('Has GPT-6 been released?')->withContext(Context::of(['claim_id' => 7])),
        );

        expect($result->meta->attempts)->toBe(2)
            ->and((string) $result->meta->correlationId)->toBe('corr-1')
            ->and($h->events->names())->toBe([
                'jev.decision.requested',
                'jev.retry.scheduled',
                'jev.web_context.resolved',
                'jev.usage.recorded',
            ])
            ->and($h->events->of(DecisionRequested::class)[0]->operation)->toBe(RunOperation::WebContext)
            ->and($h->events->of(DecisionRequested::class)[0]->statePreview)->toBe('Has GPT-6 been released?')
            ->and($h->events->of(WebContextResolved::class)[0]->context()->get('claim_id'))->toBe(7)
            ->and($h->events->of(WebContextResolved::class)[0]->result->raw)->toBeNull()
            ->and($h->events->of(TokenUsageRecorded::class)[0]->usage->inputTokens)->toBe(2140)
            ->and($h->usage->records[0]->operation)->toBe(RunOperation::WebContext)
            ->and($h->tracer->spans[0]->name)->toBe('jev.web_context');
    });
});
