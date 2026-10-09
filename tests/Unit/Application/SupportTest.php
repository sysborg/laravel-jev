<?php

declare(strict_types=1);

use Random\Engine\Mt19937;
use Random\Randomizer;
use Sysborg\LaravelJevai\Application\Support\Redactor;
use Sysborg\LaravelJevai\Application\Support\RetryPolicy;
use Sysborg\LaravelJevai\Domain\Decision\Trace;
use Sysborg\LaravelJevai\Domain\Exceptions\ApiError;
use Sysborg\LaravelJevai\Domain\Exceptions\InsufficientCredits;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidRequest;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeNotFound;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeRevisionMismatch;
use Sysborg\LaravelJevai\Domain\Exceptions\RateLimited;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;
use Sysborg\LaravelJevai\Domain\Exceptions\Unauthorized;
use Sysborg\LaravelJevai\Domain\Exceptions\UnexpectedResponse;
use Sysborg\LaravelJevai\Domain\Exceptions\UpstreamFailure;

$policy = fn (array $overrides = []): RetryPolicy => new RetryPolicy(...[
    'random' => new Randomizer(new Mt19937(7)),
    ...$overrides,
]);

describe('RetryPolicy', function () use ($policy) {
    it('backs off exponentially with jitter, capped', function () use ($policy) {
        $p = $policy(['baseDelayMs' => 250, 'maxDelayMs' => 1_000]);

        expect($p->backoffMs(1))->toBeGreaterThanOrEqual(125)->toBeLessThanOrEqual(250)
            ->and($p->backoffMs(2))->toBeGreaterThanOrEqual(250)->toBeLessThanOrEqual(500)
            ->and($p->backoffMs(3))->toBeGreaterThanOrEqual(500)->toBeLessThanOrEqual(1_000)
            ->and($p->backoffMs(9))->toBeGreaterThanOrEqual(500)->toBeLessThanOrEqual(1_000);
    });

    it('never retries errors that cannot succeed or may double bill', function ($exception) use ($policy) {
        expect($policy()->delayMs($exception, 1, 0))->toBeNull();
    })->with([
        'unauthorized' => [new Unauthorized],
        'insufficient credits' => [new InsufficientCredits],
        'judge not found' => [new JudgeNotFound('j')],
        'judge revision mismatch' => [new JudgeRevisionMismatch('j', 2)],
        'invalid request' => [new InvalidRequest],
        'other api error' => [new ApiError(500)],
        'unexpected response' => [new UnexpectedResponse(httpStatus: 200)],
        'timeout' => [new TransportFailure],
    ]);

    it('retries upstream failures and rate limits', function () use ($policy) {
        expect($policy()->delayMs(new UpstreamFailure(504), 1, 0))->toBeInt()
            ->and($policy()->delayMs(new RateLimited(3), 1, 0))->toBe(3_000)
            ->and($policy()->delayMs(new RateLimited, 1, 0))->toBeInt();
    });

    it('respects attempt and time budgets', function () use ($policy) {
        expect($policy(['maxAttempts' => 2])->delayMs(new UpstreamFailure(503), 2, 0))->toBeNull()
            ->and($policy(['maxElapsedMs' => 1_000])->delayMs(new RateLimited(2), 1, 0))->toBeNull()
            ->and(RetryPolicy::none()->delayMs(new UpstreamFailure(503), 1, 0))->toBeNull();
    });

    it('rejects invalid bounds', function () {
        new RetryPolicy(maxAttempts: 0);
    })->throws(InvalidValue::class, 'max attempts');
});

describe('Redactor', function () {
    it('parses config specs', function (string $spec, string $strategy, int $length) {
        $redactor = Redactor::fromSpec($spec);

        expect($redactor->strategy)->toBe($strategy)->and($redactor->length)->toBe($length);
    })->with([
        ['truncate:80', 'truncate', 80],
        ['truncate', 'truncate', 200],
        [' HASH ', 'hash', 200],
        ['none', 'none', 200],
        ['omit', 'omit', 200],
    ]);

    it('rejects unknown specs', function (string $spec) {
        Redactor::fromSpec($spec);
    })->throws(InvalidValue::class)->with(['mask', 'truncate:abc', 'truncate:0']);

    it('previews structured state as JSON', function () {
        expect((new Redactor('none'))->preview(['a' => 'é/b']))->toBe('{"a":"é/b"}');
    });

    it('drops only denied trace fields', function () {
        $trace = Trace::of(['email' => 'a@b.c', 'ticket_id' => 42]);

        expect((new Redactor(traceDenyKeys: ['email', 'phone']))->trace($trace)->toArray())->toBe(['ticket_id' => 42])
            ->and((new Redactor)->trace($trace))->toBe($trace);
    });
});
