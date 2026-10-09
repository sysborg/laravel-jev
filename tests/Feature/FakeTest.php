<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use Sysborg\LaravelJevai\Adapters\Testing\JevFake;
use Sysborg\LaravelJevai\Domain\Answer\ScoreAnswer;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Events\BalanceLow;
use Sysborg\LaravelJevai\Domain\Events\DecisionSucceeded;
use Sysborg\LaravelJevai\Domain\Events\RetryScheduled;
use Sysborg\LaravelJevai\Domain\Events\TokenUsageRecorded;
use Sysborg\LaravelJevai\Domain\Exceptions\InsufficientCredits;
use Sysborg\LaravelJevai\Domain\Exceptions\UpstreamFailure;
use Sysborg\LaravelJevai\Domain\Usage\BillingMode;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Facades\Jev;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Tests\Support\FakeClock;

beforeEach(function () {
    Http::preventStrayRequests();
    app()->instance(Clock::class, new FakeClock);
});

it('answers with configured values and neutral defaults, without any HTTP', function () {
    Jev::fake(['department' => 'technical', 'is_urgent' => true, 'frustration' => 2]);

    $result = Jev::state('text')
        ->noul('is_urgent', 'Urgent?')
        ->noul('is_spam', 'Spam?')
        ->choice('department', 'Team?', ['billing' => 'Payments', 'technical' => 'Bugs'])
        ->choice('language', 'Language?', ['en' => 'English', 'pt' => 'Portuguese'])
        ->score('frustration', 'Frustration?', ['Calm', 'Annoyed', 'Angry'])
        ->evaluate();

    expect($result->choice('department')->choice)->toBe('technical')
        ->and($result->noul('is_urgent')->noul)->toBe(1.0)
        ->and($result->noul('is_spam')->noul)->toBe(0.5)
        ->and($result->choice('language')->choice)->toBe('en')
        ->and($result->score('frustration')->label())->toBe('Angry')
        ->and($result->meta->connection)->toBe('fake');

    Http::assertNothingSent();
});

it('still runs the real pipeline: events, usage and retries', function () {
    Event::fake([DecisionSucceeded::class, TokenUsageRecorded::class, RetryScheduled::class, BalanceLow::class]);
    config()->set('jev.alerts.tokens_remaining_threshold', 1000);
    config()->set('cache.default', 'array');

    Jev::fake()
        ->withUsage(input: 120, output: 8)
        ->withBilling(BillingMode::Tokens, 120, 0.0, tokensRemaining: 500)
        ->failWith(new UpstreamFailure(503));

    $result = Jev::state('text')->noul('q', 'Q?')->context('ticket_id', 42)->evaluate();

    expect($result->meta->attempts)->toBe(2)->and($result->usage->inputTokens)->toBe(120);

    Event::assertDispatched(RetryScheduled::class);
    Event::assertDispatched(DecisionSucceeded::class, fn (DecisionSucceeded $e) => $e->context()->get('ticket_id') === 42);
    Event::assertDispatched(TokenUsageRecorded::class, fn (TokenUsageRecorded $e) => $e->usage->inputTokens === 120);
    Event::assertDispatched(BalanceLow::class);
    Jev::assertEvaluatedTimes(2);
});

it('plays a sequence of responses', function () {
    Jev::fake()->sequence(
        ['department' => 'billing'],
        new InsufficientCredits,
        ['department' => 'technical'],
    );

    $first = Jev::state('a')->choice('department', 'Team?', ['billing' => 'B', 'technical' => 'T'])->evaluate();

    expect(fn () => Jev::state('b')->choice('department', 'Team?', ['billing' => 'B', 'technical' => 'T'])->evaluate())
        ->toThrow(InsufficientCredits::class);

    $third = Jev::state('c')->choice('department', 'Team?', ['billing' => 'B', 'technical' => 'T'])->evaluate();

    expect($first->choice('department')->choice)->toBe('billing')
        ->and($third->choice('department')->choice)->toBe('technical');
});

it('accepts answer objects and result factories', function () {
    Jev::fake(['frustration' => new ScoreAnswer('frustration', 1.2, 0.8)]);

    expect(Jev::state('t')->score('frustration', 'F?', ['Calm', 'Angry'])->evaluate()->score('frustration')->score)->toBe(1.2);
});

it('fakes judges, web context, models, balance and the queue', function () {
    Jev::fake(['compliant' => false])
        ->webContextAnswer('no', 0.7)
        ->withAccount(['clef'], credits: 3.0, tokens: 10);

    $judged = Jev::judge('judge_1', 2)->state('transcript')->evaluate();
    $web = Jev::webContext('Has GPT-6 been released?')->resolve();
    $pending = Jev::state('later')->noul('q', 'Q?')->context('ticket_id', 7)->queue();

    expect($judged->noul('compliant')->isYes())->toBeFalse()
        ->and($judged->meta->judgeId)->toBe('judge_1')
        ->and($web->isYes())->toBeFalse()
        ->and($web->confidence)->toBe(0.7)
        ->and(Jev::models())->toBe(['clef'])
        ->and(Jev::balance()->paidInputTokensRemaining)->toBe(10)
        ->and($pending->context->get('ticket_id'))->toBe(7);

    Jev::assertJudgeUsed('judge_1', revision: 2);
    Jev::assertWebContextResolved(fn (WebContextRequest $r) => str_contains($r->question, 'GPT-6'));
    Jev::assertQueued(fn (DecisionRequest $r) => $r->context->get('ticket_id') === 7);
    Jev::assertNotEvaluated(fn (DecisionRequest $r) => $r->state->value === 'later');
});

it('provides assertions that fail with clear messages', function () {
    Jev::fake()->withUsage(input: 300);

    Jev::assertNothingEvaluated();

    Jev::state('t')->noul('q', 'Q?')->evaluate();
    Jev::state('t')->noul('q', 'Q?')->evaluate();

    Jev::assertEvaluated();
    Jev::assertEvaluated(fn (DecisionRequest $r) => $r->questions?->has('q') === true);
    Jev::assertTokensUsedLessThan(601);

    expect(fn () => Jev::assertTokensUsedLessThan(600))->toThrow(AssertionFailedError::class, 'used 600 input tokens')
        ->and(fn () => Jev::assertJudgeUsed('nope'))->toThrow(AssertionFailedError::class)
        ->and(fn () => Jev::assertEvaluatedTimes(3))->toThrow(AssertionFailedError::class, 'Expected 3');
});

it('requires fake() before assertions', function () {
    Jev::assertEvaluated();
})->throws(RuntimeException::class, 'Call Jev::fake() before Jev::assertEvaluated()');

it('exposes the fake for direct inspection', function () {
    $fake = Jev::fake();

    Jev::state('hello')->noul('q', 'Q?')->evaluate();

    expect($fake)->toBeInstanceOf(JevFake::class)
        ->and($fake->recorded()[0]->state->value)->toBe('hello');
});
