<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Domain\Answer\AnswerSet;
use Sysborg\LaravelJevai\Domain\Answer\ChoiceAnswer;
use Sysborg\LaravelJevai\Domain\Answer\NoulAnswer;
use Sysborg\LaravelJevai\Domain\Answer\ScoreAnswer;
use Sysborg\LaravelJevai\Domain\Exceptions\AnswerNotFound;
use Sysborg\LaravelJevai\Domain\Exceptions\AnswerTypeMismatch;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JevThrowable;

describe('NoulAnswer', function () {
    it('tells yes from no with a threshold', function () {
        $answer = new NoulAnswer('is_urgent', 0.95);

        expect($answer->isYes())->toBeTrue()
            ->and($answer->isYes(0.99))->toBeFalse()
            ->and((new NoulAnswer('x', 0.2))->isYes())->toBeFalse();
    });

    it('clamps floating point noise into 0..1', function () {
        expect((new NoulAnswer('x', 1.0000001))->noul)->toBe(1.0)
            ->and((new NoulAnswer('x', -0.0000001))->noul)->toBe(0.0);
    });

    it('rejects values outside 0..1', function (float $value) {
        new NoulAnswer('x', $value);
    })->throws(InvalidValue::class)->with([1.5, -0.2, NAN, INF]);
});

describe('ChoiceAnswer', function () {
    it('exposes choice, confidence and probabilities', function () {
        $answer = new ChoiceAnswer('department', 'billing', 0.98, [
            'billing' => 0.99, 'technical' => 0.01, 'sales' => 0,
        ]);

        expect($answer->is('billing'))->toBeTrue()
            ->and($answer->confidence)->toBe(0.98)
            ->and($answer->probability('technical'))->toBe(0.01)
            ->and($answer->probability('sales'))->toBe(0.0)
            ->and($answer->probability('unknown'))->toBeNull();
    });

    it('allows missing confidence and probabilities', function () {
        $answer = new ChoiceAnswer('department', 'billing');

        expect($answer->confidence)->toBeNull()->and($answer->probabilities)->toBe([]);
    });

    it('rejects non numeric probabilities', function () {
        new ChoiceAnswer('department', 'billing', null, ['billing' => 'high']);
    })->throws(InvalidValue::class, 'must be a number');
});

describe('ScoreAnswer', function () {
    $answer = fn () => new ScoreAnswer(
        'frustration',
        1.04,
        0.94,
        [0 => 'Calm', 1 => 'Frustrated', 2 => 'Very angry'],
        [0 => 0.0, 1 => 0.96, 2 => 0.04],
    );

    it('derives the most likely level and its label', function () use ($answer) {
        expect($answer()->level())->toBe(1)
            ->and($answer()->label())->toBe('Frustrated');
    });

    it('falls back to the rounded score without probabilities', function () {
        $answer = new ScoreAnswer('x', 1.6);

        expect($answer->level())->toBe(2)->and($answer->label())->toBeNull();
    });

    it('rejects negative scores', function () {
        new ScoreAnswer('x', -1.0);
    })->throws(InvalidValue::class, 'score');
});

describe('AnswerSet', function () {
    $set = fn () => AnswerSet::of(
        new NoulAnswer('is_urgent', 0.9),
        new ChoiceAnswer('department', 'billing'),
        new ScoreAnswer('frustration', 1.0),
    );

    it('returns typed answers', function () use ($set) {
        expect($set()->noul('is_urgent')->noul)->toBe(0.9)
            ->and($set()->choice('department')->choice)->toBe('billing')
            ->and($set()->score('frustration')->score)->toBe(1.0)
            ->and($set()->ids())->toBe(['is_urgent', 'department', 'frustration'])
            ->and($set())->toHaveCount(3);
    });

    it('throws when an answer is missing', function () use ($set) {
        $set()->get('missing');
    })->throws(AnswerNotFound::class, '[missing]');

    it('throws when the requested type does not match', function () use ($set) {
        $set()->choice('is_urgent');
    })->throws(AnswerTypeMismatch::class, 'is of type [noul], [choice] was requested');

    it('rejects duplicate answers', function () {
        AnswerSet::of(new NoulAnswer('a', 0.1), new NoulAnswer('a', 0.2));
    })->throws(InvalidValue::class, 'unique');

    it('throws package exceptions only', function () use ($set) {
        try {
            $set()->get('missing');
        } catch (Throwable $e) {
            expect($e)->toBeInstanceOf(JevThrowable::class);

            return;
        }

        test()->fail('Expected an exception.');
    });
});
