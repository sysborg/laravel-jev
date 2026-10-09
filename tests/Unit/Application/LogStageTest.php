<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Application\Support\Redactor;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Exceptions\UpstreamFailure;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Tests\Support\FakeDecisionGateway;
use Sysborg\LaravelJevai\Tests\Support\Harness;

$request = fn () => DecisionRequest::forQuestions('My card number is 4111 1111 1111 1111', QuestionSet::of(new NoulQuestion('q', 'Q?')));

it('logs start and success at debug with the correlation id and usage', function () use ($request) {
    $h = new Harness(['logCalls' => true, 'redactor' => new Redactor('hash')]);

    $h->client->decide($request());

    [$started, $succeeded] = $h->logger->records;

    expect([$started['level'], $started['message']])->toBe(['debug', 'Jev call started.'])
        ->and($started['context'])->toMatchArray(['correlation_id' => 'corr-1', 'operation' => 'decision', 'model' => 'jev-latest', 'connection' => 'default'])
        ->and($started['context']['state'])->toStartWith('sha256:')
        ->and([$succeeded['level'], $succeeded['message']])->toBe(['debug', 'Jev call succeeded.'])
        ->and($succeeded['context'])->toMatchArray(['correlation_id' => 'corr-1', 'input_tokens' => 120, 'input_tokens_charged' => 120, 'attempts' => 1])
        ->and(json_encode($h->logger->records))->not->toContain('4111');
});

it('logs retries as warnings and the final failure as an error', function () use ($request) {
    $h = new Harness([
        'logCalls' => true,
        'decisions' => new FakeDecisionGateway(new UpstreamFailure(503), new UpstreamFailure(503), new UpstreamFailure(503)),
    ]);

    expect(fn () => $h->client->decide($request()))->toThrow(UpstreamFailure::class);

    $levels = array_column($h->logger->records, 'level');
    $failed = $h->logger->records[array_key_last($h->logger->records)];

    expect($levels)->toBe(['debug', 'warning', 'warning', 'error'])
        ->and($h->logger->records[1]['context'])->toMatchArray(['failed_attempt' => 1, 'http_status' => 503, 'correlation_id' => 'corr-1'])
        ->and($failed['message'])->toBe('Jev call failed.')
        ->and($failed['context'])->toMatchArray(['attempts' => 3, 'http_status' => 503, 'may_have_been_billed' => false]);
});
