<?php

declare(strict_types=1);

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Question\ChoiceQuestion;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\Question\QuestionType;
use Sysborg\LaravelJevai\Domain\Question\ScoreQuestion;

describe('NoulQuestion', function () {
    it('holds its id, instructions and type', function () {
        $question = new NoulQuestion('is_urgent', 'Does this convey urgency?');

        expect($question->id)->toBe('is_urgent')
            ->and($question->instructions)->toBe('Does this convey urgency?')
            ->and($question->type())->toBe(QuestionType::Noul);
    });

    it('rejects a blank id', function () {
        new NoulQuestion(' ', 'Instructions');
    })->throws(InvalidValue::class, 'question id');

    it('rejects ids longer than 64 characters', function () {
        new NoulQuestion(str_repeat('a', 65), 'Instructions');
    })->throws(InvalidValue::class, 'at most 64');

    it('accepts ids of exactly 64 characters', function () {
        expect((new NoulQuestion(str_repeat('a', 64), 'Instructions'))->id)->toHaveLength(64);
    });

    it('rejects blank instructions', function () {
        new NoulQuestion('id', '');
    })->throws(InvalidValue::class, 'instructions');
});

describe('ChoiceQuestion', function () {
    it('exposes its options', function () {
        $question = new ChoiceQuestion('department', 'Which team?', [
            'billing' => 'Payments and refunds',
            'technical' => 'Bugs and outages',
        ]);

        expect($question->type())->toBe(QuestionType::Choice)
            ->and($question->options())->toBe(['billing', 'technical']);
    });

    it('returns numeric option names as strings', function () {
        $question = new ChoiceQuestion('tier', 'Which tier?', ['1' => 'Low', '2' => 'High']);

        expect($question->options())->toBe(['1', '2']);
    });

    it('requires at least two options', function () {
        new ChoiceQuestion('id', 'Pick', ['only' => 'One']);
    })->throws(InvalidValue::class, 'option count');

    it('allows at most 255 options', function () {
        $criteria = [];
        for ($i = 0; $i < 256; $i++) {
            $criteria["option_{$i}"] = "Option {$i}";
        }

        new ChoiceQuestion('id', 'Pick', $criteria);
    })->throws(InvalidValue::class, 'option count');

    it('rejects blank descriptions', function () {
        new ChoiceQuestion('id', 'Pick', ['a' => 'A', 'b' => ' ']);
    })->throws(InvalidValue::class, 'description');
});

describe('ScoreQuestion', function () {
    it('keeps levels ordered', function () {
        $question = new ScoreQuestion('frustration', 'How frustrated?', ['Calm', 'Frustrated', 'Very angry']);

        expect($question->type())->toBe(QuestionType::Score)
            ->and($question->levels)->toBe(['Calm', 'Frustrated', 'Very angry']);
    });

    it('requires 2 to 10 levels', function (array $levels) {
        new ScoreQuestion('id', 'Rate', $levels);
    })->throws(InvalidValue::class, 'level count')->with([
        'one level' => [['Only']],
        'eleven levels' => [array_map(fn (int $i) => "L{$i}", range(0, 10))],
    ]);

    it('rejects levels that are not a list', function () {
        new ScoreQuestion('id', 'Rate', ['low' => 'Low', 'high' => 'High']);
    })->throws(InvalidValue::class, 'ordered list');
});

describe('QuestionSet', function () {
    it('keeps questions by id in insertion order', function () {
        $set = QuestionSet::of(
            new NoulQuestion('a', 'A?'),
            new NoulQuestion('b', 'B?'),
        );

        expect($set)->toHaveCount(2)
            ->and($set->ids())->toBe(['a', 'b'])
            ->and($set->has('a'))->toBeTrue()
            ->and($set->get('missing'))->toBeNull()
            ->and(iterator_to_array($set))->toHaveKeys(['a', 'b']);
    });

    it('is immutable when adding questions', function () {
        $set = QuestionSet::of(new NoulQuestion('a', 'A?'));
        $bigger = $set->with(new NoulQuestion('b', 'B?'));

        expect($set)->toHaveCount(1)->and($bigger)->toHaveCount(2);
    });

    it('rejects duplicate ids', function () {
        QuestionSet::of(new NoulQuestion('a', 'A?'), new NoulQuestion('a', 'Again?'));
    })->throws(InvalidValue::class, 'unique');

    it('requires at least one question', function () {
        new QuestionSet;
    })->throws(InvalidValue::class, 'question count');

    it('allows at most 64 questions', function () {
        $questions = array_map(fn (int $i) => new NoulQuestion("q{$i}", 'Q?'), range(1, 65));

        new QuestionSet(...$questions);
    })->throws(InvalidValue::class, 'question count');
});
