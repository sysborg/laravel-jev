<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\JudgeRef;
use Sysborg\LaravelJevai\Domain\Events\BalanceLow;
use Sysborg\LaravelJevai\Domain\Events\CreditsExhausted;
use Sysborg\LaravelJevai\Domain\Events\DecisionFailed;
use Sysborg\LaravelJevai\Domain\Events\DecisionRequested;
use Sysborg\LaravelJevai\Domain\Events\JudgeRulesChanged;
use Sysborg\LaravelJevai\Domain\Events\RetryScheduled;
use Sysborg\LaravelJevai\Domain\Exceptions\InsufficientCredits;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeRevisionMismatch;
use Sysborg\LaravelJevai\Domain\Exceptions\UpstreamFailure;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;
use Sysborg\LaravelJevai\Tests\Support\FakeDecisionGateway;
use Sysborg\LaravelJevai\Tests\Support\Harness;

$request = fn (): DecisionRequest => DecisionRequest::forQuestions('text', QuestionSet::of(new NoulQuestion('q', 'Q?')));

describe('BalanceLow', function () use ($request) {
    it('fires once per window when the balance drops below the threshold', function () use ($request) {
        $h = new Harness(['lowBalanceTokens' => 10_000]);

        $h->client->decide($request());
        $h->client->decide($request());

        $alerts = $h->events->of(BalanceLow::class);

        expect($alerts)->toHaveCount(1)
            ->and($alerts[0]->tokensRemaining)->toBe(9_880)
            ->and($alerts[0]->threshold)->toBe(10_000)
            ->and($alerts[0]->connection)->toBe('default')
            ->and($alerts[0]->model)->toBe('jev-latest')
            ->and((string) $alerts[0]->correlationId())->toBe('corr-1')
            ->and($h->debouncer->claimed)->toBe(['jev:balance-low:default' => 3600])
            ->and(array_slice($h->events->names(), 0, 4))->toBe([
                'jev.decision.requested',
                'jev.decision.succeeded',
                'jev.usage.recorded',
                'jev.balance.low',
            ]);
    });

    it('stays quiet above the threshold or when disabled', function (?int $threshold) use ($request) {
        $h = new Harness(['lowBalanceTokens' => $threshold]);

        $h->client->decide($request());

        expect($h->events->of(BalanceLow::class))->toBe([]);
    })->with([
        'above threshold' => [9_000],
        'disabled' => [null],
    ]);

    it('never breaks the call when the debouncer fails', function () use ($request) {
        $h = new Harness(['lowBalanceTokens' => 10_000]);
        $h->debouncer->fail = true;

        expect($h->client->decide($request())->noul('q')->noul)->toBe(0.9)
            ->and($h->events->of(BalanceLow::class))->toBe([])
            ->and($h->logger->records[0]['message'])->toContain('low-balance alert failed');
    });
});

it('publishes CreditsExhausted after DecisionFailed on 402', function () use ($request) {
    $h = new Harness(['decisions' => new FakeDecisionGateway(new InsufficientCredits(errorCode: 'no_credits'))]);

    expect(fn () => $h->client->decide($request()))->toThrow(InsufficientCredits::class);

    $event = $h->events->of(CreditsExhausted::class)[0];

    expect($h->events->names())->toBe(['jev.decision.requested', 'jev.decision.failed', 'jev.credits.exhausted'])
        ->and($event->operation)->toBe(RunOperation::Decision)
        ->and($event->errorCode)->toBe('no_credits')
        ->and($event->connection)->toBe('default')
        ->and($event->model)->toBe('jev-latest');
});

it('publishes JudgeRulesChanged after DecisionFailed on 409', function () {
    $h = new Harness(['decisions' => new FakeDecisionGateway(new JudgeRevisionMismatch('judge_1', 3))]);

    expect(fn () => $h->client->decide(DecisionRequest::forJudge('text', new JudgeRef('judge_1', 3))))
        ->toThrow(JudgeRevisionMismatch::class);

    $event = $h->events->of(JudgeRulesChanged::class)[0];

    expect($h->events->names())->toBe(['jev.decision.requested', 'jev.decision.failed', 'jev.judge.rules_changed'])
        ->and($event->judgeId)->toBe('judge_1')
        ->and($event->pinnedRevision)->toBe(3)
        ->and($event->connection)->toBe('default');
});

describe('connection and model on every event (E-02)', function () use ($request) {
    it('resolves the model to the connection default when the request names none', function () use ($request) {
        $h = new Harness(['decisions' => new FakeDecisionGateway(new UpstreamFailure(503), new UpstreamFailure(503), new UpstreamFailure(503))]);

        expect(fn () => $h->client->decide($request()))->toThrow(UpstreamFailure::class);

        $requested = $h->events->of(DecisionRequested::class)[0];
        $retry = $h->events->of(RetryScheduled::class)[0];
        $failed = $h->events->of(DecisionFailed::class)[0];

        expect([$requested->model, $requested->connection])->toBe(['jev-latest', 'default'])
            ->and([$retry->model, $retry->connection])->toBe(['jev-latest', 'default'])
            ->and([$failed->model, $failed->connection])->toBe(['jev-latest', 'default'])
            ->and([$h->usage->records[0]->model, $h->usage->records[0]->connection])->toBe(['jev-latest', 'default'])
            ->and($h->tracer->spans[0]->attributes['gen_ai.request.model'])->toBe('jev-latest');
    });

    it('keeps an explicitly requested model', function () use ($request) {
        $h = new Harness;

        $h->client->decide($request()->withModel('clef'));

        expect($h->events->of(DecisionRequested::class)[0]->model)->toBe('clef');
    });
});
