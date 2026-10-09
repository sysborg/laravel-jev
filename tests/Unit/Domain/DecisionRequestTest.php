<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\JudgeRef;
use Sysborg\LaravelJevai\Domain\Decision\State;
use Sysborg\LaravelJevai\Domain\Decision\Trace;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;

$questions = fn () => QuestionSet::of(new NoulQuestion('is_urgent', 'Urgent?'));

describe('State', function () {
    it('accepts text and structured data', function () {
        expect(State::of('Server is down')->isText())->toBeTrue()
            ->and(State::of(['subject' => 'Down', 'tags' => ['p1']])->isText())->toBeFalse()
            ->and(State::of(['a' => 1])->toJson())->toBe('{"a":1}');
    });

    it('rejects empty state', function (string|array $state) {
        State::of($state);
    })->throws(InvalidValue::class, 'state')->with(['blank text' => ['  '], 'empty array' => [[]]]);

    it('rejects objects inside structured state', function () {
        State::of(['when' => new DateTimeImmutable]);
    })->throws(InvalidValue::class, 'scalars, null and arrays');
});

describe('Trace', function () {
    it('is immutable', function () {
        $trace = Trace::of(['ticket_id' => 42]);
        $other = $trace->with('tenant', 'acme');

        expect($trace->toArray())->toBe(['ticket_id' => 42])
            ->and($other->toArray())->toBe(['ticket_id' => 42, 'tenant' => 'acme'])
            ->and($other->without(['tenant'])->toArray())->toBe(['ticket_id' => 42]);
    });

    it('allows at most 64 fields', function () {
        Trace::of(array_combine(
            array_map(fn (int $i) => "k{$i}", range(1, 65)),
            range(1, 65),
        ));
    })->throws(InvalidValue::class, '64 fields');

    it('allows at most 8192 bytes', function () {
        Trace::of(['blob' => str_repeat('x', 8192)]);
    })->throws(InvalidValue::class, '8192 bytes');
});

describe('Context', function () {
    it('stores serializable application data', function () {
        $context = Context::of(['ticket_id' => 42])->with('source', 'email');

        expect($context->get('ticket_id'))->toBe(42)
            ->and($context->get('missing', 'default'))->toBe('default')
            ->and($context->has('source'))->toBeTrue()
            ->and(unserialize(serialize($context))->toArray())->toBe($context->toArray());
    });

    it('rejects objects so events stay serializable', function () {
        Context::of(['model' => new stdClass]);
    })->throws(InvalidValue::class, 'context [model]');
});

describe('JudgeRef', function () {
    it('may be pinned to a revision', function () {
        expect((new JudgeRef('judge_1'))->isPinned())->toBeFalse()
            ->and((new JudgeRef('judge_1', 3))->isPinned())->toBeTrue();
    });

    it('rejects non positive revisions', function () {
        new JudgeRef('judge_1', 0);
    })->throws(InvalidValue::class, 'revision');
});

describe('DecisionRequest', function () use ($questions) {
    it('is built for inline questions', function () use ($questions) {
        $request = DecisionRequest::forQuestions('Server is down', $questions());

        expect($request->isJudgeCall())->toBeFalse()
            ->and($request->questions)->not->toBeNull()
            ->and($request->judge)->toBeNull()
            ->and($request->model)->toBeNull()
            ->and($request->trace->isEmpty())->toBeTrue()
            ->and($request->context->isEmpty())->toBeTrue();
    });

    it('is built for a saved judge', function () {
        $request = DecisionRequest::forJudge(State::of('text'), new JudgeRef('judge_1', 2));

        expect($request->isJudgeCall())->toBeTrue()
            ->and($request->questions)->toBeNull()
            ->and($request->judge?->revision)->toBe(2);
    });

    it('returns new instances from withers', function () use ($questions) {
        $request = DecisionRequest::forQuestions('text', $questions());
        $configured = $request
            ->withModel('jev-latest')
            ->withSessionId('ticket-42')
            ->withUser('user-7')
            ->withTrace(Trace::of(['ticket_id' => 42]))
            ->withContext(Context::of(['ticket_id' => 42]));

        expect($request->model)->toBeNull()
            ->and($configured->model)->toBe('jev-latest')
            ->and($configured->sessionId)->toBe('ticket-42')
            ->and($configured->user)->toBe('user-7')
            ->and($configured->trace->get('ticket_id'))->toBe(42)
            ->and($configured->context->get('ticket_id'))->toBe(42)
            ->and($configured->questions)->toBe($request->questions);
    });

    it('limits session id and user to 256 characters', function (string $method) use ($questions) {
        DecisionRequest::forQuestions('text', $questions())->{$method}(str_repeat('a', 257));
    })->throws(InvalidValue::class, '256')->with(['withSessionId', 'withUser']);

    it('can be serialized for queued jobs', function () use ($questions) {
        $request = DecisionRequest::forQuestions(['a' => 1], $questions())->withContext(Context::of(['id' => 1]));

        expect(unserialize(serialize($request)))->toEqual($request);
    });
});
