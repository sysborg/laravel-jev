<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Domain\Account\Balance;
use Sysborg\LaravelJevai\Domain\Answer\AnswerSet;
use Sysborg\LaravelJevai\Domain\Answer\ChoiceAnswer;
use Sysborg\LaravelJevai\Domain\Answer\NoulAnswer;
use Sysborg\LaravelJevai\Domain\Answer\ScoreAnswer;
use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Decision\JudgeRef;
use Sysborg\LaravelJevai\Domain\Decision\State;
use Sysborg\LaravelJevai\Domain\Decision\Trace;
use Sysborg\LaravelJevai\Domain\Exceptions\AnswerTypeMismatch;
use Sysborg\LaravelJevai\Domain\Exceptions\CircuitOpen;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeNotFound;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeRevisionMismatch;
use Sysborg\LaravelJevai\Domain\Exceptions\Unauthorized;
use Sysborg\LaravelJevai\Domain\Exceptions\UnexpectedResponse;
use Sysborg\LaravelJevai\Domain\Question\ChoiceQuestion;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\Question\ScoreQuestion;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Run\RunMetadata;
use Sysborg\LaravelJevai\Domain\Support\Guard;
use Sysborg\LaravelJevai\Domain\Usage\Billing;
use Sysborg\LaravelJevai\Domain\Usage\BillingMode;
use Sysborg\LaravelJevai\Domain\Usage\RunOperation;
use Sysborg\LaravelJevai\Domain\Usage\RunRecord;
use Sysborg\LaravelJevai\Domain\Usage\RunStatus;
use Sysborg\LaravelJevai\Domain\Usage\Usage;
use Sysborg\LaravelJevai\Domain\Usage\UsageSummary;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextLatency;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextUsage;
use Sysborg\LaravelJevai\Domain\WebContext\WebSource;

/*
 * Exact invariants and boundaries of the Domain, one guard per case.
 * Objects are built inside the test bodies so coverage (and mutation
 * testing) attributes the checked code to these tests.
 */

/**
 * Run a callback that must throw InvalidValue and return its message.
 *
 * @param  Closure(): mixed  $build  Code expected to throw.
 * @return string The exception message.
 */
function invalidMessage(Closure $build): string
{
    try {
        $build();
    } catch (InvalidValue $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('Expected InvalidValue.');
}

describe('value guards', function () {
    it('rejects each invalid field with its own message', function (Closure $build, string $message) {
        expect(invalidMessage($build))->toBe($message);
    })->with([
        'answer question id' => [fn () => new NoulAnswer(' ', 0.5), 'Invalid answer question id: must not be blank.'],
        'choice answer choice' => [fn () => new ChoiceAnswer('q', ' '), 'Invalid answer [q] choice: must not be blank.'],
        'choice option name' => [fn () => new ChoiceQuestion('q', 'Q?', ['' => 'Empty', 'b' => 'B']), 'Invalid question [q] option name: must not be blank.'],
        'score level label' => [fn () => new ScoreQuestion('q', 'Q?', ['Low', ' ']), 'Invalid question [q] level [1]: must not be blank.'],
        'context key' => [fn () => Context::of(['' => 1]), 'Invalid context key: must not be blank.'],
        'trace key' => [fn () => Trace::of(['' => 1]), 'Invalid trace key: must not be blank.'],
        'trace value' => [fn () => Trace::of(['when' => new stdClass]), 'Invalid trace [when]: may only contain scalars, null and arrays.'],
        'request model' => [fn () => DecisionRequest::forQuestions('t', QuestionSet::of(new NoulQuestion('q', 'Q?')))->withModel(' '), 'Invalid model: must not be blank.'],
        'judge id' => [fn () => new JudgeRef(' '), 'Invalid judge id: must not be blank.'],
        'usage output' => [fn () => new Usage(0, -1), 'Invalid usage output tokens: must not be negative.'],
        'billing charged' => [fn () => new Billing(BillingMode::Tokens, -1), 'Invalid billing input tokens charged: must not be negative.'],
        'billing remaining' => [fn () => new Billing(BillingMode::Tokens, 0, 0.0, -1), 'Invalid billing tokens remaining: must not be negative.'],
        'balance tokens' => [fn () => new Balance(0.0, -1), 'Invalid paid input tokens remaining: must not be negative.'],
        'run model' => [fn () => new RunMetadata(new CorrelationId('c'), ' ', 0), 'Invalid run model: must not be blank.'],
        'run latency' => [fn () => new RunMetadata(new CorrelationId('c'), 'm', -1), 'Invalid run latency: must not be negative.'],
        'record latency' => [fn () => new RunRecord(new CorrelationId('c'), RunOperation::Decision, RunStatus::Succeeded, new DateTimeImmutable, Usage::none(), Billing::unknown(), -1, 1, Context::empty()), 'Invalid run record latency: must not be negative.'],
        'record attempts' => [fn () => new RunRecord(new CorrelationId('c'), RunOperation::Decision, RunStatus::Succeeded, new DateTimeImmutable, Usage::none(), Billing::unknown(), 0, 0, Context::empty()), 'Invalid run record attempts: must be at least 1.'],
        'web question' => [fn () => WebContextRequest::ask(' '), 'Invalid web context question: must not be blank.'],
        'web query' => [fn () => WebContextRequest::ask('Q?')->withQuery(' '), 'Invalid web context query: must not be blank.'],
        'web yes criterion' => [fn () => WebContextRequest::ask('Q?')->withCriteria(' ', 'No'), 'Invalid web context "yes" criterion: must not be blank.'],
        'web no criterion' => [fn () => WebContextRequest::ask('Q?')->withCriteria('Yes', ' '), 'Invalid web context "no" criterion: must not be blank.'],
        'web sources count' => [fn () => WebContextRequest::ask('Q?')->withSources(...array_fill(0, 11, new WebSource('t', 'https://e.x'))), 'Invalid web context sources: must have at most 10 entries.'],
        'web source highlights' => [fn () => new WebSource('t', 'https://e.x', null, ['a' => 'x']), 'Invalid web source highlights: must be a list.'],
        'web usage input' => [fn () => new WebContextUsage(-1, 0, 0, false), 'Invalid web context input tokens: must not be negative.'],
        'web usage output' => [fn () => new WebContextUsage(0, -1, 0, false), 'Invalid web context output tokens: must not be negative.'],
        'web usage calls' => [fn () => new WebContextUsage(0, 0, -1, false), 'Invalid web context jev calls: must not be negative.'],
        'web search latency' => [fn () => new WebContextLatency(-1, 0), 'Invalid web context search latency: must not be negative.'],
        'web jev latency' => [fn () => new WebContextLatency(0, -1), 'Invalid web context jev latency: must not be negative.'],
        'web result sources' => [fn () => new WebContextResult('yes', 0.5, null, null, ['a' => new WebSource('t', 'https://e.x')], null, new WebContextUsage(0, 0, 0, false), new WebContextLatency(0, 0), new RunMetadata(new CorrelationId('c'), 'm', 0), Billing::unknown()), 'Invalid web context sources: must be a list.'],
        'summary calls' => [fn () => new UsageSummary(null, -1, 0, 0, 0, 0, 0.0, 0), 'Invalid usage summary calls: must not be negative.'],
        'summary failures' => [fn () => new UsageSummary(null, 1, 2, 0, 0, 0, 0.0, 0), 'Invalid usage summary failures: must be between 0 and 1, got 2.'],
        'summary input' => [fn () => new UsageSummary(null, 0, 0, -1, 0, 0, 0.0, 0), 'Invalid usage summary input tokens: must not be negative.'],
        'summary output' => [fn () => new UsageSummary(null, 0, 0, 0, -1, 0, 0.0, 0), 'Invalid usage summary output tokens: must not be negative.'],
        'summary charged' => [fn () => new UsageSummary(null, 0, 0, 0, 0, -1, 0.0, 0), 'Invalid usage summary input tokens charged: must not be negative.'],
        'summary credits' => [fn () => new UsageSummary(null, 0, 0, 0, 0, 0, -1.0, 0), 'Invalid usage summary credits charged: must be a finite, non-negative number.'],
        'summary latency' => [fn () => new UsageSummary(null, 0, 0, 0, 0, 0, 0.0, -1), 'Invalid usage summary latency: must not be negative.'],
        'summary uncertain' => [fn () => new UsageSummary(null, 2, 1, 0, 0, 0, 0.0, 0, 2), 'Invalid usage summary uncertain billings: must be between 0 and 1, got 2.'],
        'plain data infinity' => [fn () => Context::of(['n' => [INF]]), 'Invalid context [n]: must not contain INF or NAN.'],
        'probability string' => [fn () => new ChoiceAnswer('q', 'a', null, ['a' => '0.5']), 'Invalid answer [q] probabilities [a]: must be a number.'],
        'invalid utf-8' => [fn () => State_toJsonInvalid(), 'Invalid state: must be JSON encodable (Malformed UTF-8 characters, possibly incorrectly encoded).'],
    ]);

    it('accepts values exactly at their limits', function () {
        $fields = array_combine(array_map(fn (int $i) => "k{$i}", range(1, 64)), range(1, 64));
        $justFits = ['blob' => str_repeat('x', 8192 - strlen('{"blob":""}'))];

        expect(Trace::of($fields)->toArray())->toHaveCount(64)
            ->and(Trace::of($justFits)->get('blob'))->toHaveLength(8181)
            ->and(fn () => Trace::of(['blob' => str_repeat('x', 8182)]))->toThrow(InvalidValue::class, 'at most 8192 bytes')
            ->and(invalidMessage(fn () => Trace::of([...$fields, 'k65' => 65])))->toBe('Invalid trace: must have at most 64 fields.')
            ->and(WebContextRequest::ask('Q?')->withNumResults(10)->numResults)->toBe(10)
            ->and(WebContextRequest::ask('Q?')->withNumResults(1)->numResults)->toBe(1)
            ->and(WebContextRequest::ask('Q?')->withSources(...array_fill(0, 10, new WebSource('t', 'https://e.x')))->sources)->toHaveCount(10)
            ->and((new JudgeRef('j', 1))->revision)->toBe(1)
            ->and((new UsageSummary(null, 2, 2, 0, 0, 0, 0.0, 0, 2))->errorRate())->toBe(1.0)
            ->and(new RunRecord(new CorrelationId('c'), RunOperation::Decision, RunStatus::Succeeded, new DateTimeImmutable, Usage::none(), Billing::unknown(), 0, 1, Context::empty()))->toBeInstanceOf(RunRecord::class);
    });
});

/**
 * Encode a state holding invalid UTF-8.
 *
 * @return string Never returns.
 */
function State_toJsonInvalid(): string
{
    return State::of(["\xB1\x31"])->toJson();
}

describe('Guard', function () {
    it('clamps only within the probability tolerance', function () {
        expect(Guard::probability(1.000001, 'p'))->toBe(1.0)
            ->and(Guard::probability(-0.000001, 'p'))->toBe(0.0)
            ->and(fn () => Guard::probability(1.0000011, 'p'))->toThrow(InvalidValue::class)
            ->and(fn () => Guard::probability(-0.0000011, 'p'))->toThrow(InvalidValue::class);
    });

    it('turns integer probabilities into floats', function () {
        expect(Guard::probabilities(['a' => 1, 'b' => 0], 'p'))->toBe(['a' => 1.0, 'b' => 0.0]);
    });

    it('encodes JSON like Jev expects: unescaped slashes and unicode, zero fractions kept', function () {
        expect(Guard::json(['url' => 'https://a.b/c', 'name' => 'São Paulo', 'score' => 1.0], 'f'))
            ->toBe('{"url":"https://a.b/c","name":"São Paulo","score":1.0}');
    });

    it('accepts every plain scalar type', function () {
        Guard::plainData([null, true, 1, 1.5, 'x', ['nested' => [false]]], 'f');

        expect(fn () => Guard::plainData([fopen('php://memory', 'r')], 'f'))->toThrow(InvalidValue::class, 'scalars, null and arrays');
    });
});

describe('behaviour at the edges', function () {
    it('treats a noul exactly at the threshold as yes', function () {
        expect((new NoulAnswer('q', 0.5))->isYes())->toBeTrue()
            ->and((new NoulAnswer('q', 0.7))->isYes(0.7))->toBeTrue();
    });

    it('rounds the score to the nearest level when no distribution was returned', function () {
        expect((new ScoreAnswer('q', 1.4))->level())->toBe(1)
            ->and((new ScoreAnswer('q', 1.5))->level())->toBe(2);
    });

    it('reports every answer type mismatch', function () {
        $answers = AnswerSet::of(new NoulAnswer('n', 0.5), new ChoiceAnswer('c', 'a'), new ScoreAnswer('s', 1.0));

        expect(fn () => $answers->noul('c'))->toThrow(AnswerTypeMismatch::class, '[choice], [noul] was requested')
            ->and(fn () => $answers->choice('s'))->toThrow(AnswerTypeMismatch::class, '[score], [choice] was requested')
            ->and(fn () => $answers->score('n'))->toThrow(AnswerTypeMismatch::class, '[noul], [score] was requested');
    });

    it('counts retries only from the second attempt', function () {
        $meta = new RunMetadata(new CorrelationId('c'), 'm', 0);

        expect($meta->wasRetried())->toBeFalse()
            ->and($meta->withTiming(10, 2)->wasRetried())->toBeTrue();
    });

    it('needs both balances empty to be exhausted', function () {
        expect((new Balance(0.5, 0))->isExhausted())->toBeFalse()
            ->and((new Balance(0.0, 1))->isExhausted())->toBeFalse()
            ->and((new Balance(0.0, 0))->isExhausted())->toBeTrue();
    });

    it('treats only charges above zero as charged', function () {
        expect((new Billing(BillingMode::Tokens, 1))->wasCharged())->toBeTrue()
            ->and((new Billing(BillingMode::Credits, 0, 0.000001))->wasCharged())->toBeTrue()
            ->and((new Billing(BillingMode::Tokens, 0, 0.0))->wasCharged())->toBeFalse();
    });

    it('needs both branches to compare evidence', function () {
        $result = fn (?ChoiceAnswer $without) => new WebContextResult(
            'yes', 0.5, new ChoiceAnswer('with_web', 'yes'), $without, [], null,
            new WebContextUsage(0, 0, 0, false), new WebContextLatency(0, 0), new RunMetadata(new CorrelationId('c'), 'm', 0), Billing::unknown(),
        );

        expect($result(null)->evidenceChangedDecision())->toBeFalse()
            ->and($result(new ChoiceAnswer('without_web', 'no'))->evidenceChangedDecision())->toBeTrue();
    });

    it('keeps existing context values when merging', function () {
        expect(Context::of(['a' => 1])->merge(['b' => 2])->toArray())->toBe(['a' => 1, 'b' => 2]);
    });

    it('takes the judge from the request when the response does not name it', function () {
        $request = DecisionRequest::forJudge('t', new JudgeRef('judge_9', 4));
        $result = new DecisionResult(AnswerSet::of(new NoulAnswer('v', 0.1)), Usage::none(), Billing::unknown(), new RunMetadata(new CorrelationId('c'), 'm', 0));

        $record = RunRecord::forDecision($request, $result, new DateTimeImmutable);

        expect($record->judgeId)->toBe('judge_9')
            ->and($record->judgeRevision)->toBe(4)
            ->and($record->httpStatus)->toBe(200);
    });
});

describe('exception details', function () {
    it('builds exact messages and statuses', function () {
        $pinned = new JudgeRevisionMismatch('judge_1', 3);
        $unpinned = new JudgeRevisionMismatch('judge_1');
        $custom = new JudgeRevisionMismatch('judge_1', 3, 'Rules changed.');
        $missing = new JudgeNotFound('judge_2');
        $customMissing = new JudgeNotFound('judge_2', 'Gone.');

        expect($pinned->getMessage())->toBe('Jev judge [judge_1] no longer matches revision [3].')
            ->and($pinned->httpStatus)->toBe(409)
            ->and($unpinned->getMessage())->toBe('Jev judge [judge_1] no longer matches revision [unknown].')
            ->and($custom->getMessage())->toBe('Rules changed.')
            ->and($missing->getMessage())->toBe('Jev judge [judge_2] was not found or is not accessible.')
            ->and($missing->httpStatus)->toBe(404)
            ->and($customMissing->getMessage())->toBe('Gone.')
            ->and((new Unauthorized)->getCode())->toBe(0)
            ->and((new CircuitOpen(18, 'tenant-a'))->getMessage())
            ->toBe('Jev circuit breaker is open for connection [tenant-a] after repeated upstream failures; retry in 18s.')
            ->and((new CircuitOpen(5))->getMessage())
            ->toBe('Jev circuit breaker is open for connection [default] after repeated upstream failures; retry in 5s.');
    });

    it('considers only 2xx (or unknown) unexpected responses as possibly billed', function (?int $status, bool $billed) {
        expect((new UnexpectedResponse(httpStatus: $status))->mayHaveBeenBilled())->toBe($billed);
    })->with([
        [null, true], [199, false], [200, true], [204, true], [299, true], [300, false], [500, false],
    ]);
});
