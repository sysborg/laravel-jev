<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Sysborg\LaravelJevai\Domain\Exceptions\Unauthorized;
use Sysborg\LaravelJevai\Domain\Exceptions\UnexpectedResponse;
use Sysborg\LaravelJevai\Ports\Driven\AccountGateway;

beforeEach(fn () => Http::preventStrayRequests());

it('lists models from GET /v1/models', function () {
    Http::fake(['*' => Http::response(jevFixture('models'))]);

    expect(app(AccountGateway::class)->models())
        ->toBe(['jev-latest', 'jev-preview', 'jev-1.13.0', 'laya-english', 'clef']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://jev-ai.pro/api/v1/models'
        && $request->method() === 'GET');
});

it('accepts the other plausible model list shapes', function (mixed $body) {
    Http::fake(['*' => Http::response($body)]);

    expect(app(AccountGateway::class)->models())->toBe(['jev-latest', 'clef']);
})->with([
    'list of names' => [['jev-latest', 'clef', 'clef']],
    'models key' => [['models' => [['name' => 'jev-latest'], ['name' => 'clef']]]],
    'mixed, with junk' => [['data' => ['jev-latest', ['id' => 'clef'], 42, ['other' => 'x']]]],
]);

it('fails when no model list can be found', function () {
    Http::fake(['*' => Http::response(['unexpected' => true])]);

    app(AccountGateway::class)->models();
})->throws(UnexpectedResponse::class, 'no list of models');

it('reads the balance from GET /v1/credits', function () {
    Http::fake(['*' => Http::response(jevFixture('credits'))]);

    $balance = app(AccountGateway::class)->balance();

    expect($balance->creditsRemaining)->toBe(42.0)
        ->and($balance->paidInputTokensRemaining)->toBe(1_250_000)
        ->and($balance->isExhausted())->toBeFalse();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://jev-ai.pro/api/v1/credits');
});

it('rejects an incomplete balance', function () {
    Http::fake(['*' => Http::response(['creditsRemaining' => 1])]);

    app(AccountGateway::class)->balance();
})->throws(UnexpectedResponse::class, 'paidInputTokensRemaining');

it('maps errors', function () {
    Http::fake(['*' => Http::response(jevFixture('error'), 401)]);

    app(AccountGateway::class)->balance();
})->throws(Unauthorized::class);
