<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\WebContext\WebSource;
use Sysborg\LaravelJevai\Tests\Support\Harness;

describe('JevClient', function () {
    it('queues decisions with a fresh correlation id and validates them first', function () {
        $h = new Harness(['maxBodyBytes' => 400]);
        $request = DecisionRequest::forQuestions('text', QuestionSet::of(new NoulQuestion('q', 'Q?')))
            ->withContext(Context::of(['ticket_id' => 42]));

        $pending = $h->client->queue($request);

        expect((string) $pending->correlationId)->toBe('corr-1')
            ->and($pending->context->get('ticket_id'))->toBe(42)
            ->and($pending->queuedAt)->toEqual($h->clock->now())
            ->and($h->queue->pushed)->toHaveCount(1)
            ->and($h->queue->pushed[0]['request'])->toBe($request)
            ->and((string) $h->queue->pushed[0]['correlationId'])->toBe('corr-1')
            ->and($h->decisions->calls)->toBe([])
            ->and(fn () => $h->client->queue(DecisionRequest::forQuestions(str_repeat('x', 500), QuestionSet::of(new NoulQuestion('q', 'Q?')))))
            ->toThrow(InvalidValue::class);
    });

    it('lists models and reads the balance', function () {
        $h = new Harness;

        expect($h->client->models())->toBe(['jev-latest', 'clef'])
            ->and($h->client->balance()->creditsRemaining)->toBe(42.0);
    });
});

describe('DecisionBuilder', function () {
    it('builds the full request', function () {
        $h = new Harness;

        $request = $h->client->model('clef')
            ->state('My card was charged twice!')
            ->noul('is_urgent', 'Urgent?')
            ->choice('department', 'Which team?', ['billing' => 'Payments', 'technical' => 'Bugs'])
            ->score('frustration', 'How frustrated?', ['Calm', 'Angry'])
            ->session('ticket-42')
            ->user('user-7')
            ->trace(['ticket_id' => 42])
            ->trace('pipeline', 'triage')
            ->context('ticket_id', 42)
            ->context(['source' => 'email'])
            ->toRequest();

        expect($request->model)->toBe('clef')
            ->and($request->state->value)->toBe('My card was charged twice!')
            ->and($request->questions?->ids())->toBe(['is_urgent', 'department', 'frustration'])
            ->and($request->sessionId)->toBe('ticket-42')
            ->and($request->user)->toBe('user-7')
            ->and($request->trace->toArray())->toBe(['ticket_id' => 42, 'pipeline' => 'triage'])
            ->and($request->context->toArray())->toBe(['ticket_id' => 42, 'source' => 'email']);
    });

    it('is immutable', function () {
        $h = new Harness;
        $base = $h->client->state('text')->noul('a', 'A?');

        $base->noul('b', 'B?');

        expect($base->toRequest()->questions?->ids())->toBe(['a']);
    });

    it('builds judge requests', function () {
        $request = (new Harness)->client->judge('judge_1', 3)->state('transcript')->toRequest();

        expect($request->isJudgeCall())->toBeTrue()->and($request->judge?->revision)->toBe(3);
    });

    it('rejects incomplete or contradictory requests', function (Closure $build, string $message) {
        $build((new Harness)->client)->toRequest();
    })->throws(InvalidValue::class)->with([
        'no state' => [fn ($c) => $c->request()->noul('q', 'Q?'), 'state()'],
        'no questions' => [fn ($c) => $c->state('text'), 'at least one question'],
        'both' => [fn ($c) => $c->judge('j')->state('text')->noul('q', 'Q?'), 'not both'],
    ]);

    it('evaluates and queues through the client', function () {
        $h = new Harness;

        $result = $h->client->state('text')->noul('q', 'Q?')->context('ticket_id', 7)->evaluate();
        $pending = $h->client->state('text')->noul('q', 'Q?')->queue();

        expect($result->noul('q')->noul)->toBe(0.9)
            ->and($h->decisions->calls[0]['request']->context->get('ticket_id'))->toBe(7)
            ->and((string) $pending->correlationId)->toBe('corr-2');
    });
});

describe('WebContextBuilder', function () {
    it('builds and resolves the request', function () {
        $h = new Harness;
        $builder = $h->client->webContext('Has GPT-6 been released?')
            ->query('GPT-6 release')
            ->criteria('Released', 'Not released')
            ->numResults(3)
            ->attribution()
            ->sources(new WebSource('Notes', 'https://example.com'))
            ->context('claim_id', 7);

        $request = $builder->toRequest();

        expect($request->query)->toBe('GPT-6 release')
            ->and($request->criteria)->toBe(['yes' => 'Released', 'no' => 'Not released'])
            ->and($request->numResults)->toBe(3)
            ->and($request->attribution)->toBeTrue()
            ->and($request->usesOwnSources())->toBeTrue()
            ->and($request->context->get('claim_id'))->toBe(7)
            ->and($builder->resolve()->isYes())->toBeTrue()
            ->and($h->webContext->calls)->toHaveCount(1);
    });
});
