<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\JudgeRef;
use Sysborg\LaravelJevai\Domain\Decision\Trace;
use Sysborg\LaravelJevai\Domain\Exceptions\ApiError;
use Sysborg\LaravelJevai\Domain\Exceptions\InsufficientCredits;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidRequest;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeNotFound;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeRevisionMismatch;
use Sysborg\LaravelJevai\Domain\Exceptions\RateLimited;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;
use Sysborg\LaravelJevai\Domain\Exceptions\Unauthorized;
use Sysborg\LaravelJevai\Domain\Exceptions\UnexpectedResponse;
use Sysborg\LaravelJevai\Domain\Exceptions\UpstreamFailure;
use Sysborg\LaravelJevai\Domain\Question\ChoiceQuestion;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\Question\ScoreQuestion;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Usage\BillingMode;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\DecisionGateway;
use Sysborg\LaravelJevai\Tests\Support\FakeClock;

beforeEach(function () {
    Http::preventStrayRequests();
    app()->instance(Clock::class, new FakeClock(stepMs: 412));
});

$questions = fn () => QuestionSet::of(
    new NoulQuestion('is_urgent', 'Does this convey urgency?'),
    new ChoiceQuestion('department', 'Which team?', [
        'billing' => 'Payments and refunds',
        'technical' => 'Bugs and outages',
        'sales' => 'Pricing',
    ]),
    new ScoreQuestion('frustration', 'How frustrated?', ['Calm', 'Frustrated', 'Very angry']),
);

$decide = fn (DecisionRequest $request) => app(DecisionGateway::class)->decide($request, new CorrelationId('corr-1'));

/**
 * Run a callback and return the exception it throws.
 *
 * Example:
 * ```php
 * $e = caught(fn () => $gateway->decide($request, $id));
 * ```
 *
 * @param  callable(): mixed  $callback  Code expected to throw.
 * @return Throwable The thrown exception.
 */
function caught(callable $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected an exception, none was thrown.');
}

describe('request', function () use ($questions, $decide) {
    it('posts the documented body with bearer auth to /v1/systemone', function () use ($questions, $decide) {
        Http::fake(['*' => Http::response(jevFixture('decision-success'))]);

        $decide(DecisionRequest::forQuestions('My card was charged twice!', $questions())
            ->withSessionId('ticket-42')
            ->withUser('user-7')
            ->withTrace(Trace::of(['ticket_id' => 42, 'correlation_id' => 'corr-1'])));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            expect($request->url())->toBe('https://jev-ai.pro/api/v1/systemone')
                ->and($request->method())->toBe('POST')
                ->and($request->header('Authorization'))->toBe(['Bearer test-key'])
                ->and($request->header('Accept'))->toBe(['application/json'])
                ->and($request->header('User-Agent')[0])->toContain('sysborg/laravel-jevai')
                ->and(json_decode($request->body(), true))->toBe([
                    'model' => 'jev-latest',
                    'state' => 'My card was charged twice!',
                    'questions' => [
                        'is_urgent' => ['type' => 'noul', 'instructions' => 'Does this convey urgency?'],
                        'department' => [
                            'type' => 'choice',
                            'instructions' => 'Which team?',
                            'criteria' => [
                                'billing' => 'Payments and refunds',
                                'technical' => 'Bugs and outages',
                                'sales' => 'Pricing',
                            ],
                        ],
                        'frustration' => [
                            'type' => 'score',
                            'instructions' => 'How frustrated?',
                            'criteria' => ['Calm', 'Frustrated', 'Very angry'],
                        ],
                    ],
                    'session_id' => 'ticket-42',
                    'user' => 'user-7',
                    'trace' => ['ticket_id' => 42, 'correlation_id' => 'corr-1'],
                ]);

            return true;
        });
    });

    it('encodes maps with numeric keys as JSON objects', function () use ($decide) {
        Http::fake(['*' => Http::response(['answers' => ['1' => ['type' => 'choice', 'choice' => '2']]])]);

        $decide(DecisionRequest::forQuestions('text', QuestionSet::of(
            new ChoiceQuestion('1', 'Which tier?', ['1' => 'Low', '2' => 'High']),
        )));

        Http::assertSent(fn (Request $request) => str_contains($request->body(), '"questions":{"1":{')
            && str_contains($request->body(), '"criteria":{"1":"Low","2":"High"}'));
    });

    it('omits optional fields that are not set', function () use ($questions, $decide) {
        Http::fake(['*' => Http::response(jevFixture('decision-success'))]);

        $decide(DecisionRequest::forQuestions('text', $questions()));

        Http::assertSent(fn (Request $request) => array_keys($request->data()) === ['model', 'state', 'questions']);
    });

    it('uses the model of the request over the connection default', function () use ($questions, $decide) {
        Http::fake(['*' => Http::response(jevFixture('decision-success'))]);

        $decide(DecisionRequest::forQuestions('text', $questions())->withModel('clef'));

        Http::assertSent(fn (Request $request) => $request['model'] === 'clef');
    });

    it('sends structured state as is', function () use ($questions, $decide) {
        Http::fake(['*' => Http::response(jevFixture('decision-success'))]);

        $decide(DecisionRequest::forQuestions(['subject' => 'Refund', 'tags' => ['p1']], $questions()));

        Http::assertSent(fn (Request $request) => $request['state'] === ['subject' => 'Refund', 'tags' => ['p1']]);
    });

    it('sends judgeId and revision instead of questions for judge calls', function () use ($decide) {
        Http::fake(['*' => Http::response(jevFixture('decision-judge-success'))]);

        $decide(DecisionRequest::forJudge('transcript', new JudgeRef('judge_123', 3)));

        Http::assertSent(fn (Request $request) => $request->data() === [
            'model' => 'jev-latest',
            'state' => 'transcript',
            'judgeId' => 'judge_123',
            'revision' => 3,
        ]);
    });
});

describe('response', function () use ($questions, $decide) {
    it('maps answers, usage, billing and metadata', function () use ($questions, $decide) {
        Http::fake(['*' => Http::response(jevFixture('decision-success'), 200, [
            'X-Jev-Run-Id' => 'run_789',
            'X-Jev-Tokens-Remaining' => '9880',
        ])]);

        $result = $decide(DecisionRequest::forQuestions('text', $questions()));

        expect($result->noul('is_urgent')->noul)->toBe(0.95)
            ->and($result->choice('department')->choice)->toBe('billing')
            ->and($result->choice('department')->probability('technical'))->toBe(0.01)
            ->and($result->score('frustration')->level())->toBe(1)
            ->and($result->score('frustration')->label())->toBe('Frustrated')
            ->and($result->usage->inputTokens)->toBe(120)
            ->and($result->usage->outputTokens)->toBe(8)
            ->and($result->usage->cost)->toBeNull()
            ->and($result->billing->mode)->toBe(BillingMode::Tokens)
            ->and($result->billing->inputTokensCharged)->toBe(120)
            ->and($result->billing->creditsCharged)->toBe(0.0)
            ->and($result->billing->tokensRemaining)->toBe(9880)
            ->and((string) $result->meta->correlationId)->toBe('corr-1')
            ->and($result->meta->model)->toBe('jev-latest')
            ->and($result->meta->responseId)->toBe('dec_01JBX4')
            ->and($result->meta->provider)->toBe('jev')
            ->and($result->meta->runId)->toBe('run_789')
            ->and($result->meta->connection)->toBe('default')
            ->and($result->meta->attempts)->toBe(1)
            ->and($result->meta->latencyMs)->toBe(412)
            ->and($result->raw)->toHaveKey('some_future_field');
    });

    it('falls back to the billing headers when the body has no billing', function () use ($decide) {
        Http::fake(['*' => Http::response(jevFixture('decision-judge-success'), 200, [
            'X-Jev-Billing' => 'credits-fallback',
            'X-Jev-Credits-Charged' => '1',
            'X-Jev-Paid-Input-Tokens-Used' => '0',
            'X-Jev-Judge-Id' => 'judge_123',
            'X-Jev-Judge-Revision' => '4',
        ])]);

        $result = $decide(DecisionRequest::forJudge('transcript', new JudgeRef('judge_123')));

        expect($result->billing->mode)->toBe(BillingMode::CreditsFallback)
            ->and($result->billing->creditsCharged)->toBe(1.0)
            ->and($result->billing->inputTokensCharged)->toBe(0)
            ->and($result->meta->judgeId)->toBe('judge_123')
            ->and($result->meta->judgeRevision)->toBe(4)
            ->and($result->meta->runId)->toBeNull()
            ->and($result->noul('is_compliant')->isYes())->toBeFalse();
    });

    it('infers the answer type from the asked question when Jev omits it', function () use ($decide) {
        Http::fake(['*' => Http::response(['answers' => ['is_urgent' => ['noul' => 0.7]]])]);

        $result = $decide(DecisionRequest::forQuestions('text', QuestionSet::of(new NoulQuestion('is_urgent', 'Urgent?'))));

        expect($result->noul('is_urgent')->noul)->toBe(0.7)
            ->and($result->usage->totalTokens())->toBe(0)
            ->and($result->billing->mode)->toBe(BillingMode::Unknown);
    });

    it('rejects bodies it cannot understand as possibly billed', function (mixed $body, string $message) use ($questions, $decide) {
        Http::fake(['*' => Http::response($body)]);

        $e = caught(fn () => $decide(DecisionRequest::forQuestions('text', $questions())));

        expect($e)->toBeInstanceOf(UnexpectedResponse::class)
            ->and($e->getMessage())->toContain($message)
            ->and($e->mayHaveBeenBilled())->toBeTrue()
            ->and($e->httpStatus)->toBe(200);
    })->with([
        'not json' => ['<html>oops</html>', 'not a JSON object'],
        'no answers' => [['model' => 'jev-latest'], '[answers] is missing'],
        'mistyped field' => [['answers' => ['is_urgent' => ['type' => 'noul', 'noul' => 'high']]], '[answers.is_urgent.noul] must be a number'],
        'probability out of range' => [['answers' => ['is_urgent' => ['type' => 'noul', 'noul' => 4.2]]], 'outside its documented range'],
        'unknown type without question' => [['answers' => ['other' => ['type' => 'ranking']]], '[answers.other.type]'],
    ]);

    it('rejects malformed billing headers', function () use ($questions, $decide) {
        Http::fake(['*' => Http::response(jevFixture('decision-success'), 200, ['X-Jev-Tokens-Remaining' => 'lots'])]);

        $e = caught(fn () => $decide(DecisionRequest::forQuestions('text', $questions())));

        expect($e)->toBeInstanceOf(UnexpectedResponse::class)
            ->and($e->getMessage())->toContain('[X-Jev-Tokens-Remaining] must be an integer');
    });
});

describe('errors', function () use ($questions, $decide) {
    it('maps each documented status to its exception', function (int $status, string $class, bool $retryable) use ($questions, $decide) {
        Http::fake(['*' => Http::response(jevFixture('error'), $status)]);

        $e = caught(fn () => $decide(DecisionRequest::forQuestions('text', $questions())));

        expect($e)->toBeInstanceOf($class)
            ->and($e)->toBeInstanceOf(JevException::class)
            ->and($e->httpStatus)->toBe($status)
            ->and($e->errorCode)->toBe('jev_error')
            ->and($e->getMessage())->toBe('Something went wrong on the Jev side.')
            ->and($e->isRetryable())->toBe($retryable)
            ->and($e->mayHaveBeenBilled())->toBeFalse();

        Http::assertSentCount(1);
    })->with([
        [400, InvalidRequest::class, false],
        [401, Unauthorized::class, false],
        [402, InsufficientCredits::class, false],
        [404, ApiError::class, false],
        [409, ApiError::class, false],
        [422, InvalidRequest::class, false],
        [429, RateLimited::class, true],
        [500, ApiError::class, false],
        [502, UpstreamFailure::class, true],
        [503, UpstreamFailure::class, true],
        [504, UpstreamFailure::class, true],
    ]);

    it('names the judge on 404 and 409', function (int $status, string $class) use ($decide) {
        Http::fake(['*' => Http::response(jevFixture('error'), $status)]);

        $e = caught(fn () => $decide(DecisionRequest::forJudge('text', new JudgeRef('judge_123', 3))));

        expect($e)->toBeInstanceOf($class)->and($e->judgeId)->toBe('judge_123');
    })->with([
        [404, JudgeNotFound::class],
        [409, JudgeRevisionMismatch::class],
    ]);

    it('uses a default message when the error body is not documented', function () use ($questions, $decide) {
        Http::fake(['*' => Http::response('Bad Gateway', 502)]);

        $e = caught(fn () => $decide(DecisionRequest::forQuestions('text', $questions())));

        expect($e)->toBeInstanceOf(UpstreamFailure::class)
            ->and($e->getMessage())->toBe('Jev upstream failure.')
            ->and($e->errorCode)->toBeNull();
    });

    it('reads Retry-After in seconds and as an HTTP date', function (string $header, int $min, int $max) use ($questions, $decide) {
        Http::fake(['*' => Http::response(jevFixture('error'), 429, ['Retry-After' => $header])]);

        $e = caught(fn () => $decide(DecisionRequest::forQuestions('text', $questions())));

        expect($e)->toBeInstanceOf(RateLimited::class)
            ->and($e->retryAfterSeconds)->toBeGreaterThanOrEqual($min)->toBeLessThanOrEqual($max);
    })->with([
        'seconds' => ['30', 30, 30],
        'http date' => [fn () => gmdate('D, d M Y H:i:s', time() + 60).' GMT', 55, 60],
        'past date' => ['Wed, 21 Oct 2015 07:28:00 GMT', 0, 0],
    ]);

    it('turns connection failures into a possibly billed transport failure', function () use ($questions, $decide) {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $e = caught(fn () => $decide(DecisionRequest::forQuestions('text', $questions())));

        expect($e)->toBeInstanceOf(TransportFailure::class)
            ->and($e->mayHaveBeenBilled())->toBeTrue()
            ->and($e->isRetryable())->toBeFalse()
            ->and($e->getMessage())->toContain('[default]')
            ->and($e->getMessage())->not->toContain('test-key')
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    });

    it('truncates very long error messages', function () use ($questions, $decide) {
        Http::fake(['*' => Http::response(['error' => ['code' => 'x', 'message' => str_repeat('a', 2000)]], 422)]);

        $e = caught(fn () => $decide(DecisionRequest::forQuestions('text', $questions())));

        expect(mb_strlen($e->getMessage()))->toBe(500);
    });
});
