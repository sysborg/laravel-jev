<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sysborg\LaravelJevai\Adapters\Testing\JevFake;
use Sysborg\LaravelJevai\Domain\Answer\ChoiceAnswer;
use Sysborg\LaravelJevai\Domain\Answer\NoulAnswer;
use Sysborg\LaravelJevai\Domain\Answer\ScoreAnswer;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\JudgeRef;
use Sysborg\LaravelJevai\Domain\Exceptions\RateLimited;
use Sysborg\LaravelJevai\Domain\Question\ChoiceQuestion;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\Question\ScoreQuestion;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Usage\BillingMode;
use Sysborg\LaravelJevai\Ports\Driven\DecisionGateway;

/*
 * Every DecisionGateway adapter must pass these tests, so the fake used in
 * application tests behaves like the real HTTP adapter.
 */

$gateways = [
    'http' => [function (string $scenario): DecisionGateway {
        Http::preventStrayRequests();
        Http::fake(['*' => match ($scenario) {
            'success' => Http::response(jevFixture('decision-success')),
            'judge' => Http::response(['answers' => ['verdict' => ['type' => 'noul', 'noul' => 0.1]], 'usage' => ['input_tokens' => 50, 'output_tokens' => 1]], 200, ['X-Jev-Judge-Id' => 'judge_1']),
            default => Http::response(jevFixture('error'), 429, ['Retry-After' => '3']),
        }]);

        return app(DecisionGateway::class);
    }],
    'fake' => [function (string $scenario): DecisionGateway {
        return match ($scenario) {
            'success' => new JevFake(['department' => 'billing', 'is_urgent' => 0.95, 'frustration' => 1]),
            'judge' => new JevFake(['verdict' => 0.1]),
            default => (new JevFake)->failWith(new RateLimited(3)),
        };
    }],
];

$questions = fn () => QuestionSet::of(
    new NoulQuestion('is_urgent', 'Urgent?'),
    new ChoiceQuestion('department', 'Team?', ['billing' => 'B', 'technical' => 'T', 'sales' => 'S']),
    new ScoreQuestion('frustration', 'Frustration?', ['Calm', 'Frustrated', 'Very angry']),
);

it('answers every asked question with the matching answer type', function (Closure $gateway) use ($questions) {
    $result = $gateway('success')->decide(DecisionRequest::forQuestions('text', $questions()), new CorrelationId('contract-1'));

    expect($result->answer('is_urgent'))->toBeInstanceOf(NoulAnswer::class)
        ->and($result->answer('department'))->toBeInstanceOf(ChoiceAnswer::class)
        ->and($result->answer('frustration'))->toBeInstanceOf(ScoreAnswer::class)
        ->and($result->choice('department')->choice)->toBe('billing')
        ->and($result->score('frustration')->level())->toBe(1);
})->with($gateways);

it('stamps the correlation id and reports one attempt with usage and billing', function (Closure $gateway) use ($questions) {
    $result = $gateway('success')->decide(DecisionRequest::forQuestions('text', $questions()), new CorrelationId('contract-2'));

    expect((string) $result->meta->correlationId)->toBe('contract-2')
        ->and($result->meta->attempts)->toBe(1)
        ->and($result->meta->model)->not->toBe('')
        ->and($result->meta->connection)->not->toBeNull()
        ->and($result->usage->inputTokens)->toBeGreaterThan(0)
        ->and($result->billing->mode)->toBeInstanceOf(BillingMode::class);
})->with($gateways);

it('answers judge calls and names the judge', function (Closure $gateway) {
    $result = $gateway('judge')->decide(DecisionRequest::forJudge('transcript', new JudgeRef('judge_1')), new CorrelationId('contract-3'));

    expect($result->answers)->toHaveCount(1)
        ->and($result->noul('verdict')->noul)->toBe(0.1)
        ->and($result->meta->judgeId)->toBe('judge_1');
})->with($gateways);

it('reports failures as typed Jev exceptions', function (Closure $gateway) use ($questions) {
    try {
        $gateway('rate-limited')->decide(DecisionRequest::forQuestions('text', $questions()), new CorrelationId('contract-4'));
    } catch (RateLimited $e) {
        expect($e->retryAfterSeconds)->toBe(3)->and($e->isRetryable())->toBeTrue();

        return;
    }

    test()->fail('Expected RateLimited.');
})->with($gateways);
