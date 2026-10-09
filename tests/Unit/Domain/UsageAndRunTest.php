<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Domain\Answer\AnswerSet;
use Sysborg\LaravelJevai\Domain\Answer\ChoiceAnswer;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Run\RunMetadata;
use Sysborg\LaravelJevai\Domain\Usage\Billing;
use Sysborg\LaravelJevai\Domain\Usage\BillingMode;
use Sysborg\LaravelJevai\Domain\Usage\Usage;

describe('Usage', function () {
    it('totals tokens and keeps an unknown cost as null, not zero', function () {
        $usage = new Usage(120, 8);

        expect($usage->totalTokens())->toBe(128)->and($usage->cost)->toBeNull();
    });

    it('rejects negative tokens', function () {
        new Usage(-1, 0);
    })->throws(InvalidValue::class, 'input tokens');
});

describe('BillingMode', function () {
    it('parses header values and falls back to Unknown', function (?string $value, BillingMode $mode) {
        expect(BillingMode::fromValue($value))->toBe($mode);
    })->with([
        ['tokens', BillingMode::Tokens],
        ['credits', BillingMode::Credits],
        [' Credits-Fallback ', BillingMode::CreditsFallback],
        ['tokens-fallback', BillingMode::TokensFallback],
        ['something-new', BillingMode::Unknown],
        [null, BillingMode::Unknown],
    ]);

    it('knows fallbacks and credit charges', function () {
        expect(BillingMode::CreditsFallback->isFallback())->toBeTrue()
            ->and(BillingMode::Tokens->isFallback())->toBeFalse()
            ->and(BillingMode::CreditsFallback->chargesCredits())->toBeTrue()
            ->and(BillingMode::TokensFallback->chargesCredits())->toBeFalse();
    });
});

describe('Billing', function () {
    it('knows whether anything was charged', function () {
        expect(Billing::unknown()->wasCharged())->toBeFalse()
            ->and((new Billing(BillingMode::Tokens, 120))->wasCharged())->toBeTrue()
            ->and((new Billing(BillingMode::Credits, 0, 1.0))->wasCharged())->toBeTrue();
    });

    it('rejects negative charges', function () {
        new Billing(BillingMode::Credits, 0, -1.0);
    })->throws(InvalidValue::class, 'credits charged');
});

describe('CorrelationId', function () {
    it('accepts uuids and simple identifiers', function (string $value) {
        expect((string) new CorrelationId($value))->toBe($value);
    })->with(['0192f1c4-7a0e-7c3b-9a8e-1f2d3c4b5a69', 'ticket-42', 'job:123.4_a']);

    it('rejects values unsafe for headers and logs', function (string $value) {
        new CorrelationId($value);
    })->throws(InvalidValue::class)->with(['', ' spaced', "line\nbreak", str_repeat('a', 129)]);
});

describe('RunMetadata', function () {
    it('reports retries', function () {
        $meta = new RunMetadata(new CorrelationId('c-1'), 'jev-latest', 120, attempts: 2);

        expect($meta->wasRetried())->toBeTrue();
    });

    it('requires at least one attempt', function () {
        new RunMetadata(new CorrelationId('c-1'), 'jev-latest', 120, attempts: 0);
    })->throws(InvalidValue::class, 'attempts');
});

describe('DecisionResult', function () {
    it('exposes answers and can drop the raw body', function () {
        $result = new DecisionResult(
            AnswerSet::of(new ChoiceAnswer('department', 'billing')),
            new Usage(120, 8),
            new Billing(BillingMode::Tokens, 120, 0.0, 9_880),
            new RunMetadata(new CorrelationId('c-1'), 'jev-latest', 120),
            ['answers' => []],
        );

        expect($result->choice('department')->choice)->toBe('billing')
            ->and($result->answer('department'))->toBeInstanceOf(ChoiceAnswer::class)
            ->and($result->raw)->not->toBeNull()
            ->and($result->withoutRaw()->raw)->toBeNull()
            ->and(unserialize(serialize($result)))->toEqual($result);
    });
});
