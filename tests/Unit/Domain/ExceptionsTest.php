<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Domain\Exceptions\ApiError;
use Sysborg\LaravelJevai\Domain\Exceptions\InsufficientCredits;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidRequest;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Exceptions\JevThrowable;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeNotFound;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeRevisionMismatch;
use Sysborg\LaravelJevai\Domain\Exceptions\RateLimited;
use Sysborg\LaravelJevai\Domain\Exceptions\TransportFailure;
use Sysborg\LaravelJevai\Domain\Exceptions\Unauthorized;
use Sysborg\LaravelJevai\Domain\Exceptions\UnexpectedResponse;
use Sysborg\LaravelJevai\Domain\Exceptions\UpstreamFailure;

it('maps each failure to its status, retry and billing semantics', function (
    JevException $exception,
    ?int $status,
    bool $retryable,
    bool $mayHaveBeenBilled,
) {
    expect($exception)->toBeInstanceOf(JevThrowable::class)
        ->and($exception->httpStatus)->toBe($status)
        ->and($exception->isRetryable())->toBe($retryable)
        ->and($exception->mayHaveBeenBilled())->toBe($mayHaveBeenBilled);
})->with([
    '401' => [new Unauthorized, 401, false, false],
    '402' => [new InsufficientCredits, 402, false, false],
    '404' => [new JudgeNotFound('judge_1'), 404, false, false],
    '409' => [new JudgeRevisionMismatch('judge_1', 3), 409, false, false],
    '422' => [new InvalidRequest, 422, false, false],
    '400' => [new InvalidRequest(httpStatus: 400), 400, false, false],
    '429' => [new RateLimited(30), 429, true, false],
    '502' => [new UpstreamFailure(502), 502, true, false],
    '504' => [new UpstreamFailure(504), 504, true, false],
    'timeout' => [new TransportFailure, null, false, true],
    'bad 200 body' => [new UnexpectedResponse(httpStatus: 200), 200, false, true],
    'bad 500 body' => [new UnexpectedResponse(httpStatus: 500), 500, false, false],
    'other' => [new ApiError(500, errorCode: 'internal'), 500, false, false],
]);

it('keeps the Jev error code', function () {
    expect((new Unauthorized(errorCode: 'invalid_key'))->errorCode)->toBe('invalid_key');
});

it('exposes Retry-After on rate limits', function () {
    expect((new RateLimited(30))->retryAfterSeconds)->toBe(30);
});

it('names the judge involved', function () {
    $mismatch = new JudgeRevisionMismatch('judge_1', 3);

    expect($mismatch->judgeId)->toBe('judge_1')
        ->and($mismatch->requestedRevision)->toBe(3)
        ->and($mismatch->getMessage())->toContain('judge_1')
        ->and((new JudgeNotFound('judge_9'))->getMessage())->toContain('judge_9');
});
