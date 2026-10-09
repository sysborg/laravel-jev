<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Sysborg\LaravelJevai\Domain\Exceptions\RateLimited;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Usage\BillingMode;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Domain\WebContext\WebSource;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\WebContextGateway;
use Sysborg\LaravelJevai\Tests\Support\FakeClock;

beforeEach(function () {
    Http::preventStrayRequests();
    app()->instance(Clock::class, new FakeClock(stepMs: 980));
});

$resolve = fn (WebContextRequest $request) => app(WebContextGateway::class)->resolve($request, new CorrelationId('corr-2'));

it('posts the documented body to /v1/web-context', function () use ($resolve) {
    Http::fake(['*' => Http::response(jevFixture('web-context-success'))]);

    $resolve(WebContextRequest::ask('Has OpenAI released GPT-6?')
        ->withQuery('OpenAI releases GPT-6 announcement')
        ->withCriteria('OpenAI has publicly released a model named GPT-6', 'No model named GPT-6 has been released')
        ->withNumResults(4)
        ->withAttribution());

    Http::assertSent(fn (Request $request) => $request->url() === 'https://jev-ai.pro/api/v1/web-context'
        && $request->method() === 'POST'
        && json_decode($request->body(), true) === [
            'question' => 'Has OpenAI released GPT-6?',
            'query' => 'OpenAI releases GPT-6 announcement',
            'criteria' => [
                'yes' => 'OpenAI has publicly released a model named GPT-6',
                'no' => 'No model named GPT-6 has been released',
            ],
            'num_results' => 4,
            'attribution' => true,
        ]);
});

it('sends own sources instead of searching', function () use ($resolve) {
    Http::fake(['*' => Http::response(jevFixture('web-context-success'))]);

    $resolve(WebContextRequest::ask('Q?')->withSources(
        new WebSource('Notes', 'https://example.com/notes', new DateTimeImmutable('2026-09-10T00:00:00Z'), ['GPT-6 is live']),
    ));

    Http::assertSent(fn (Request $request) => $request['sources'] === [[
        'title' => 'Notes',
        'url' => 'https://example.com/notes',
        'publishedDate' => '2026-09-10T00:00:00.000+00:00',
        'highlights' => ['GPT-6 is live'],
    ]]);
});

it('maps the decision, both branches, sources, usage, latency and metadata', function () use ($resolve) {
    Http::fake(['*' => Http::response(jevFixture('web-context-success'), 200, [
        'X-Jev-Billing' => 'tokens',
        'X-Jev-Paid-Input-Tokens-Used' => '172140',
        'X-Jev-Run-Id' => 'run_wc',
    ])]);

    $result = $resolve(WebContextRequest::ask('Has OpenAI released GPT-6?'));

    expect($result->decision)->toBe('yes')
        ->and($result->isYes())->toBeTrue()
        ->and($result->confidence)->toBe(0.88)
        ->and($result->withWeb?->choice)->toBe('yes')
        ->and($result->withWeb?->probability('no'))->toBe(0.12)
        ->and($result->withoutWeb?->choice)->toBe('no')
        ->and($result->evidenceChangedDecision())->toBeTrue()
        ->and($result->sources)->toHaveCount(1)
        ->and($result->sources[0]->url)->toBe('https://example.com/gpt-6')
        ->and($result->sources[0]->publishedAt?->format('Y-m-d'))->toBe('2026-09-10')
        ->and($result->sources[0]->highlights)->toBe(['OpenAI released GPT-6 today.'])
        ->and($result->sourceWeights)->toBeNull()
        ->and($result->usage->inputTokens)->toBe(2140)
        ->and($result->usage->jevCalls)->toBe(2)
        ->and($result->usage->webSearch)->toBeTrue()
        ->and($result->latency->totalMs())->toBe(970)
        ->and($result->billing->mode)->toBe(BillingMode::Tokens)
        ->and($result->billing->inputTokensCharged)->toBe(172140)
        ->and($result->meta->runId)->toBe('run_wc')
        ->and($result->meta->latencyMs)->toBe(980)
        ->and($result->meta->model)->toBe('jev-latest');
});

it('maps source weights given as a list', function () use ($resolve) {
    Http::fake(['*' => Http::response([...jevFixture('web-context-success'), 'source_weights' => [0.7]])]);

    expect($resolve(WebContextRequest::ask('Q?'))->sourceWeights)->toBe([0 => 0.7]);
});

it('drops unreadable publication dates instead of failing', function () use ($resolve) {
    $fixture = jevFixture('web-context-success');
    $fixture['sources'][0]['publishedDate'] = 'yesterday-ish';
    Http::fake(['*' => Http::response($fixture)]);

    expect($resolve(WebContextRequest::ask('Q?'))->sources[0]->publishedAt)->toBeNull();
});

it('maps errors like the decision endpoint', function () use ($resolve) {
    Http::fake(['*' => Http::response(jevFixture('error'), 429, ['Retry-After' => '5'])]);

    expect(fn () => $resolve(WebContextRequest::ask('Q?')))->toThrow(RateLimited::class);
});
