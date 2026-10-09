<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Events\BalanceLow;
use Sysborg\LaravelJevai\Domain\Events\CreditsExhausted;
use Sysborg\LaravelJevai\Domain\Events\DecisionFailed;
use Sysborg\LaravelJevai\Domain\Events\DecisionRequested;
use Sysborg\LaravelJevai\Domain\Events\DecisionSucceeded;
use Sysborg\LaravelJevai\Domain\Events\JevEvent;
use Sysborg\LaravelJevai\Domain\Events\JudgeRulesChanged;
use Sysborg\LaravelJevai\Domain\Events\RetryScheduled;
use Sysborg\LaravelJevai\Domain\Events\TokenUsageRecorded;
use Sysborg\LaravelJevai\Domain\Exceptions\InsufficientCredits;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeRevisionMismatch;
use Sysborg\LaravelJevai\Domain\Exceptions\RateLimited;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;

use function Sysborg\LaravelJevai\Tests\Support\fakeDecisionResult;

$id = new CorrelationId('corr-1');
$context = Context::of(['ticket_id' => 42]);
$now = new DateTimeImmutable('2026-10-09 12:00:00');
$result = fakeDecisionResult(DecisionRequest::forQuestions('t', QuestionSet::of(new NoulQuestion('q', 'Q?'))), $id);

it('survives serialization for queued listeners', function (JevEvent $event) {
    $copy = unserialize(serialize($event));

    expect($copy)->toEqual($event)
        ->and($copy->name())->toBe($event->name())
        ->and((string) $copy->correlationId())->toBe('corr-1')
        ->and($copy->context()->get('ticket_id'))->toBe(42);
})->with([
    'requested' => [new DecisionRequested(RunOperation::Decision, $id, $context, $now, 'jev-latest', ['q'])],
    'succeeded' => [new DecisionSucceeded(RunOperation::Decision, $result, $context, $now)],
    'failed' => [DecisionFailed::fromException(RunOperation::Decision, $id, $context, $now, new TransportFailure, 1, 30_000)],
    'retry' => [RetryScheduled::fromException(RunOperation::Decision, $id, $context, $now, new RateLimited(2), 1, 2_000)],
    'balance low' => [new BalanceLow($id, $context, $now, 8_000, 10_000, 'default', 'jev-latest')],
    'credits exhausted' => [CreditsExhausted::fromException(RunOperation::Decision, $id, $context, $now, new InsufficientCredits, 'jev-latest', 'default')],
    'judge rules changed' => [JudgeRulesChanged::fromException($id, $context, $now, new JudgeRevisionMismatch('judge_1', 2), 'default')],
    'usage' => [new TokenUsageRecorded(RunOperation::Decision, $id, $context, $now, 'jev-latest', 'default', $result->usage, $result->billing)],
]);

it('describes failures without holding the exception', function () use ($id, $context, $now) {
    $event = DecisionFailed::fromException(RunOperation::Judge, $id, $context, $now, new RateLimited(5, errorCode: 'slow_down'), 3, 9_000, 'clef');

    expect($event->exception)->toBe(RateLimited::class)
        ->and($event->httpStatus)->toBe(429)
        ->and($event->errorCode)->toBe('slow_down')
        ->and($event->retryable)->toBeTrue()
        ->and($event->mayHaveBeenBilled)->toBeFalse()
        ->and($event->attempts)->toBe(3)
        ->and($event->model)->toBe('clef')
        ->and($event->occurredAt())->toBe($now);
});
