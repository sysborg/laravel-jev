<?php

declare(strict_types=1);

use Random\Engine\Mt19937;
use Random\Randomizer;
use Sysborg\LaravelJevai\Application\Support\RetryPolicy;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Events\CircuitClosed;
use Sysborg\LaravelJevai\Domain\Events\CircuitOpened;
use Sysborg\LaravelJevai\Domain\Events\DecisionFailed;
use Sysborg\LaravelJevai\Domain\Exceptions\BudgetExceeded;
use Sysborg\LaravelJevai\Domain\Exceptions\CircuitOpen;
use Sysborg\LaravelJevai\Domain\Exceptions\LocallyRateLimited;
use Sysborg\LaravelJevai\Domain\Exceptions\Unauthorized;
use Sysborg\LaravelJevai\Domain\Exceptions\UpstreamFailure;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\Usage\RunStatus;
use Sysborg\LaravelJevai\Tests\Support\FakeClock;
use Sysborg\LaravelJevai\Tests\Support\FakeDecisionGateway;
use Sysborg\LaravelJevai\Tests\Support\Harness;

$request = fn () => DecisionRequest::forQuestions('text', QuestionSet::of(new NoulQuestion('q', 'Q?')));

describe('rate limiter', function () use ($request) {
    it('throws above the per-minute limit without sending', function () use ($request) {
        $h = new Harness(['perMinute' => 2]);

        $h->client->decide($request());
        $h->client->decide($request());

        $e = null;

        try {
            $h->client->decide($request());
        } catch (LocallyRateLimited $e) {
        }

        expect($e)->toBeInstanceOf(LocallyRateLimited::class)
            ->and($e?->retryAfterSeconds)->toBe(60)
            ->and($e?->limitPerMinute)->toBe(2)
            ->and($e?->mayHaveBeenBilled())->toBeFalse()
            ->and($h->decisions->calls)->toHaveCount(2)
            ->and($h->events->of(DecisionFailed::class)[0]->exception)->toBe(LocallyRateLimited::class)
            ->and($h->usage->records[2]->status)->toBe(RunStatus::Failed);
    });

    it('waits for the next minute when blocking and the wait is short', function () use ($request) {
        $h = new Harness(['perMinute' => 1, 'block' => true, 'clock' => new FakeClock(now: new DateTimeImmutable('2026-10-09 12:00:55'))]);

        $h->client->decide($request());
        $h->client->decide($request());

        expect($h->decisions->calls)->toHaveCount(2)
            ->and($h->clock->sleeps)->toBe([5_000]);
    });

    it('throws when blocking would wait too long', function () use ($request) {
        $h = new Harness(['perMinute' => 1, 'block' => true]);

        $h->client->decide($request());

        expect(fn () => $h->client->decide($request()))->toThrow(LocallyRateLimited::class)
            ->and($h->clock->sleeps)->toBe([]);
    });

    it('counts every retry attempt', function () use ($request) {
        $h = new Harness([
            'perMinute' => 2,
            'decisions' => new FakeDecisionGateway(new UpstreamFailure(503), new UpstreamFailure(503), null),
        ]);

        expect(fn () => $h->client->decide($request()))->toThrow(LocallyRateLimited::class)
            ->and($h->decisions->calls)->toHaveCount(2);
    });

    it('lets calls through when the store fails', function () use ($request) {
        $h = new Harness(['perMinute' => 1]);
        $h->counters->fail = true;

        $h->client->decide($request());
        $h->client->decide($request());

        expect($h->decisions->calls)->toHaveCount(2)
            ->and($h->logger->records[0]['message'])->toContain('was let through');
    });
});

describe('circuit breaker', function () use ($request) {
    it('opens after consecutive upstream failures, fails fast, then closes on success', function () use ($request) {
        $h = new Harness([
            'failureThreshold' => 2,
            'decisions' => new FakeDecisionGateway(new UpstreamFailure(503), new UpstreamFailure(502), null, null),
        ]);

        expect(fn () => $h->client->decide($request()))->toThrow(CircuitOpen::class)
            ->and($h->decisions->calls)->toHaveCount(2)
            ->and($h->events->of(CircuitOpened::class))->toHaveCount(1)
            ->and($h->events->of(CircuitOpened::class)[0]->consecutiveFailures)->toBe(2)
            ->and($h->events->of(CircuitOpened::class)[0]->openSeconds)->toBe(30);

        expect(fn () => $h->client->decide($request()))->toThrow(CircuitOpen::class)
            ->and($h->decisions->calls)->toHaveCount(2);

        $h->clock->travel(31);

        expect($h->client->decide($request())->noul('q')->noul)->toBe(0.9)
            ->and($h->events->of(CircuitClosed::class))->toHaveCount(1)
            ->and($h->counters->values)->not->toHaveKey('jev:default:circuit:failures');
    });

    it('ignores client errors and resets on success', function () use ($request) {
        $h = new Harness([
            'failureThreshold' => 2,
            'policy' => RetryPolicy::none(),
            'decisions' => new FakeDecisionGateway(new UpstreamFailure(503), null, new UpstreamFailure(503), new Unauthorized, null),
        ]);

        expect(fn () => $h->client->decide($request()))->toThrow(UpstreamFailure::class);
        $h->client->decide($request());
        expect(fn () => $h->client->decide($request()))->toThrow(UpstreamFailure::class);
        expect(fn () => $h->client->decide($request()))->toThrow(Unauthorized::class);
        $h->client->decide($request());

        expect($h->events->of(CircuitOpened::class))->toBe([]);
    });
});

describe('daily budget', function () use ($request) {
    it('refuses calls once the charged tokens of the day reach the limit', function () use ($request) {
        $h = new Harness(['dailyInputTokens' => 200]);

        $h->client->decide($request());
        $h->client->decide($request());

        $e = null;

        try {
            $h->client->decide($request());
        } catch (BudgetExceeded $e) {
        }

        expect($e?->usedTokens)->toBe(240)
            ->and($e?->limitTokens)->toBe(200)
            ->and($h->decisions->calls)->toHaveCount(2)
            ->and($h->counters->values['jev:default:budget:2026-10-09'])->toBe(240);

        $h->clock->travel(86_400);

        expect($h->client->decide($request())->noul('q')->noul)->toBe(0.9);
    });
});

it('is all off by default', function () use ($request) {
    $h = new Harness(['decisions' => new FakeDecisionGateway(...array_fill(0, 10, new UpstreamFailure(503))), 'policy' => new RetryPolicy(maxAttempts: 10, maxElapsedMs: 600_000, random: new Randomizer(new Mt19937(1)))]);

    expect(fn () => $h->client->decide($request()))->toThrow(UpstreamFailure::class)
        ->and($h->decisions->calls)->toHaveCount(10)
        ->and($h->counters->values)->toBe([]);
});
