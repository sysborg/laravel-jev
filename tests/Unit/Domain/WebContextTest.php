<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Domain\Answer\ChoiceAnswer;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Run\RunMetadata;
use Sysborg\LaravelJevai\Domain\Usage\Billing;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextLatency;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextUsage;
use Sysborg\LaravelJevai\Domain\WebContext\WebSource;

describe('WebContextRequest', function () {
    it('has documented defaults', function () {
        $request = WebContextRequest::ask('Has OpenAI released GPT-6?');

        expect($request->numResults)->toBe(6)
            ->and($request->attribution)->toBeFalse()
            ->and($request->usesOwnSources())->toBeFalse()
            ->and($request->query)->toBeNull()
            ->and($request->criteria)->toBeNull();
    });

    it('is configured immutably', function () {
        $request = WebContextRequest::ask('Q?');
        $configured = $request
            ->withQuery('search terms')
            ->withCriteria('It happened', 'It did not')
            ->withNumResults(3)
            ->withAttribution()
            ->withSources(new WebSource('Title', 'https://example.com'));

        expect($request->numResults)->toBe(6)
            ->and($configured->criteria)->toBe(['yes' => 'It happened', 'no' => 'It did not'])
            ->and($configured->numResults)->toBe(3)
            ->and($configured->attribution)->toBeTrue()
            ->and($configured->usesOwnSources())->toBeTrue();
    });

    it('limits results to 1..10', function (int $count) {
        WebContextRequest::ask('Q?')->withNumResults($count);
    })->throws(InvalidValue::class, 'result count')->with([0, 11]);

    it('allows at most 10 own sources', function () {
        WebContextRequest::ask('Q?')->withSources(
            ...array_map(fn (int $i) => new WebSource("T{$i}", "https://example.com/{$i}"), range(1, 11)),
        );
    })->throws(InvalidValue::class, 'at most 10');
});

describe('WebContextResult', function () {
    $result = fn (string $withWeb, string $withoutWeb) => new WebContextResult(
        decision: $withWeb,
        confidence: 0.88,
        withWeb: new ChoiceAnswer('with_web', $withWeb, 0.88, ['yes' => 0.88, 'no' => 0.12]),
        withoutWeb: new ChoiceAnswer('without_web', $withoutWeb),
        sources: [new WebSource('Title', 'https://example.com', new DateTimeImmutable('2026-09-10'), ['quote'])],
        sourceWeights: null,
        usage: new WebContextUsage(2140, 12, 2, true),
        latency: new WebContextLatency(560, 410),
        meta: new RunMetadata(new CorrelationId('c-1'), 'jev-latest', 980),
        billing: Billing::unknown(),
    );

    it('tells whether the evidence changed the decision', function () use ($result) {
        expect($result('yes', 'no')->evidenceChangedDecision())->toBeTrue()
            ->and($result('yes', 'yes')->evidenceChangedDecision())->toBeFalse()
            ->and($result('yes', 'no')->isYes())->toBeTrue();
    });

    it('exposes usage and latency', function () use ($result) {
        $r = $result('yes', 'no');

        expect($r->usage->toUsage()->totalTokens())->toBe(2152)
            ->and($r->usage->jevCalls)->toBe(2)
            ->and($r->latency->totalMs())->toBe(970);
    });
});
