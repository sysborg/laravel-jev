<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Domain\Account\Balance;
use Sysborg\LaravelJevai\Domain\Answer\AnswerSet;
use Sysborg\LaravelJevai\Domain\Answer\ChoiceAnswer;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Decision\JudgeRef;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\RateLimited;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Run\RunMetadata;
use Sysborg\LaravelJevai\Domain\Usage\Billing;
use Sysborg\LaravelJevai\Domain\Usage\BillingMode;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;
use Sysborg\LaravelJevai\Domain\Usage\RunRecord;
use Sysborg\LaravelJevai\Domain\Usage\RunStatus;
use Sysborg\LaravelJevai\Domain\Usage\Usage;
use Sysborg\LaravelJevai\Domain\Usage\UsageGrouping;
use Sysborg\LaravelJevai\Domain\Usage\UsageQuery;
use Sysborg\LaravelJevai\Domain\Usage\UsageSummary;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextLatency;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextUsage;

$meta = fn () => new RunMetadata(new CorrelationId('c-1'), 'jev-latest', 120, responseId: 'r-1', runId: 'run-1');

$decisionResult = fn () => new DecisionResult(
    AnswerSet::of(new ChoiceAnswer('department', 'billing')),
    new Usage(120, 8),
    new Billing(BillingMode::Tokens, 120, 0.0, 9_880),
    $meta(),
    ['answers' => []],
);

describe('Balance', function () {
    it('knows when the account is exhausted', function () {
        expect((new Balance(0.0, 0))->isExhausted())->toBeTrue()
            ->and((new Balance(1.0, 0))->isExhausted())->toBeFalse()
            ->and((new Balance(0.0, 10))->isExhausted())->toBeFalse();
    });

    it('rejects negative balances', function () {
        new Balance(-1.0, 0);
    })->throws(InvalidValue::class, 'credits remaining');
});

describe('RunMetadata timing', function () use ($meta, $decisionResult) {
    it('is stamped immutably with the final timing and connection', function () use ($meta) {
        $original = $meta();
        $timed = $original->withTiming(latencyMs: 1_240, attempts: 3)->withConnection('tenant-a');

        expect($original->attempts)->toBe(1)
            ->and($timed->attempts)->toBe(3)
            ->and($timed->latencyMs)->toBe(1_240)
            ->and($timed->connection)->toBe('tenant-a')
            ->and($timed->runId)->toBe('run-1')
            ->and($timed->correlationId->equals($original->correlationId))->toBeTrue();
    });

    it('can be swapped on results', function () use ($decisionResult) {
        $result = $decisionResult();
        $updated = $result->withMeta($result->meta->withTiming(900, 2));

        expect($updated->meta->attempts)->toBe(2)
            ->and($updated->raw)->toBe($result->raw)
            ->and($result->meta->attempts)->toBe(1);
    });
});

describe('RunRecord', function () use ($decisionResult, $meta) {
    it('is built from a successful inline decision', function () use ($decisionResult) {
        $request = DecisionRequest::forQuestions('text', QuestionSet::of(new NoulQuestion('q', 'Q?')))
            ->withSessionId('ticket-42')
            ->withUser('user-7')
            ->withContext(Context::of(['ticket_id' => 42]));

        $record = RunRecord::forDecision($request, $decisionResult(), new DateTimeImmutable('2026-10-09 12:00:00'));

        expect($record->operation)->toBe(RunOperation::Decision)
            ->and($record->status)->toBe(RunStatus::Succeeded)
            ->and($record->succeeded())->toBeTrue()
            ->and($record->usage->inputTokens)->toBe(120)
            ->and($record->billing->inputTokensCharged)->toBe(120)
            ->and($record->model)->toBe('jev-latest')
            ->and($record->runId)->toBe('run-1')
            ->and($record->sessionId)->toBe('ticket-42')
            ->and($record->user)->toBe('user-7')
            ->and($record->context->get('ticket_id'))->toBe(42)
            ->and($record->httpStatus)->toBe(200)
            ->and($record->payload)->toBeNull();
    });

    it('marks judge calls and keeps the judge reference', function () use ($decisionResult) {
        $request = DecisionRequest::forJudge('text', new JudgeRef('judge_1', 4));

        $record = RunRecord::forDecision($request, $decisionResult(), new DateTimeImmutable);

        expect($record->operation)->toBe(RunOperation::Judge)
            ->and($record->judgeId)->toBe('judge_1')
            ->and($record->judgeRevision)->toBe(4);
    });

    it('is built from a successful web-context call', function () use ($meta) {
        $result = new WebContextResult(
            'yes', 0.9, null, null, [], null,
            new WebContextUsage(2140, 12, 2, true), new WebContextLatency(560, 410),
            $meta(), new Billing(BillingMode::Tokens, 2140),
        );

        $record = RunRecord::forWebContext(WebContextRequest::ask('Q?'), $result, new DateTimeImmutable);

        expect($record->operation)->toBe(RunOperation::WebContext)
            ->and($record->usage->totalTokens())->toBe(2152)
            ->and($record->billing->inputTokensCharged)->toBe(2140);
    });

    it('is built from a failure, flagging uncertain billing', function () {
        $failure = fn ($error) => RunRecord::forFailure(
            RunOperation::Decision, new CorrelationId('c-9'), $error, new DateTimeImmutable,
            latencyMs: 30_000, attempts: 1, context: Context::empty(), model: 'jev-latest',
        );

        $timeout = $failure(new TransportFailure);
        $limited = $failure(new RateLimited(10, errorCode: 'rate_limited'));

        expect($timeout->status)->toBe(RunStatus::Failed)
            ->and($timeout->succeeded())->toBeFalse()
            ->and($timeout->billingUncertain)->toBeTrue()
            ->and($timeout->httpStatus)->toBeNull()
            ->and($timeout->usage->totalTokens())->toBe(0)
            ->and($timeout->billing->mode)->toBe(BillingMode::Unknown)
            ->and($limited->billingUncertain)->toBeFalse()
            ->and($limited->httpStatus)->toBe(429)
            ->and($limited->errorCode)->toBe('rate_limited');
    });

    it('can be serialized for queued storage', function () use ($decisionResult) {
        $request = DecisionRequest::forQuestions('text', QuestionSet::of(new NoulQuestion('q', 'Q?')));
        $record = RunRecord::forDecision($request, $decisionResult(), new DateTimeImmutable);

        expect(unserialize(serialize($record)))->toEqual($record);
    });
});

describe('UsageQuery', function () {
    it('is built immutably', function () {
        $query = UsageQuery::between(new DateTimeImmutable('-7 days'), new DateTimeImmutable);
        $filtered = $query
            ->groupBy(UsageGrouping::Model)
            ->whereModel('clef')
            ->whereConnection('tenant-a')
            ->whereOperation(RunOperation::Judge)
            ->whereUser('user-7')
            ->whereSession('ticket-42');

        expect($query->grouping)->toBe(UsageGrouping::None)
            ->and($query->model)->toBeNull()
            ->and($filtered->grouping)->toBe(UsageGrouping::Model)
            ->and($filtered->model)->toBe('clef')
            ->and($filtered->connection)->toBe('tenant-a')
            ->and($filtered->operation)->toBe(RunOperation::Judge)
            ->and($filtered->user)->toBe('user-7')
            ->and($filtered->sessionId)->toBe('ticket-42');
    });

    it('requires the period to start before it ends', function () {
        $now = new DateTimeImmutable;

        UsageQuery::between($now, $now);
    })->throws(InvalidValue::class, 'period');
});

describe('UsageSummary', function () {
    it('derives error rate and average latency', function () {
        $summary = new UsageSummary('jev-latest', 40, 1, 48_000, 900, 48_000, 0.0, 18_000, 1);

        expect($summary->errorRate())->toBe(0.025)
            ->and($summary->averageLatencyMs())->toBe(450.0);
    });

    it('handles empty groups', function () {
        $summary = new UsageSummary(null, 0, 0, 0, 0, 0, 0.0, 0);

        expect($summary->errorRate())->toBe(0.0)->and($summary->averageLatencyMs())->toBe(0.0);
    });

    it('rejects more failures than calls', function () {
        new UsageSummary(null, 1, 2, 0, 0, 0, 0.0, 0);
    })->throws(InvalidValue::class, 'failures');
});
