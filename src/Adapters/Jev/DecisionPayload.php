<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use stdClass;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Question\ChoiceQuestion;
use Sysborg\LaravelJevai\Domain\Question\Question;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\Question\ScoreQuestion;

/**
 * Builds the JSON body of `POST /v1/systemone`.
 *
 * Only documented fields are sent, because Jev rejects unknown ones.
 * Maps whose keys may look numeric are cast to objects so they are always
 * encoded as JSON objects, never as arrays.
 */
final class DecisionPayload
{
    /**
     * Build the body.
     *
     * Example:
     * ```php
     * DecisionPayload::from($request, 'jev-latest');
     * // ['model' => 'jev-latest', 'state' => '...', 'questions' => {...}, 'session_id' => '...']
     * ```
     *
     * @param  DecisionRequest  $request  The request to send.
     * @param  string  $defaultModel  Model used when the request does not name one.
     * @return array<string, mixed> The JSON body.
     */
    public static function from(DecisionRequest $request, string $defaultModel): array
    {
        $payload = [
            'model' => $request->model ?? $defaultModel,
            'state' => $request->state->value,
        ];

        if ($request->questions !== null) {
            $payload['questions'] = self::questions($request->questions);
        }

        if ($request->judge !== null) {
            $payload['judgeId'] = $request->judge->id;

            if ($request->judge->revision !== null) {
                $payload['revision'] = $request->judge->revision;
            }
        }

        if ($request->sessionId !== null) {
            $payload['session_id'] = $request->sessionId;
        }

        if ($request->user !== null) {
            $payload['user'] = $request->user;
        }

        if (! $request->trace->isEmpty()) {
            $payload['trace'] = (object) $request->trace->toArray();
        }

        return $payload;
    }

    /**
     * Encode the question set as an object keyed by question id.
     *
     * Example:
     * ```php
     * self::questions($set); // {"is_urgent": {"type": "noul", "instructions": "..."}}
     * ```
     *
     * @param  QuestionSet  $questions  The questions.
     * @return stdClass Id => question definition.
     */
    private static function questions(QuestionSet $questions): stdClass
    {
        $encoded = new stdClass;

        foreach ($questions as $id => $question) {
            $encoded->{$id} = self::question($question);
        }

        return $encoded;
    }

    /**
     * Encode one question definition.
     *
     * Example:
     * ```php
     * self::question(new ScoreQuestion('f', 'How frustrated?', ['Calm', 'Angry']));
     * // ['type' => 'score', 'instructions' => 'How frustrated?', 'criteria' => ['Calm', 'Angry']]
     * ```
     *
     * @param  Question  $question  The question.
     * @return array<string, mixed> `type`, `instructions` and, for choice and score, `criteria`.
     */
    private static function question(Question $question): array
    {
        $definition = [
            'type' => $question->type()->value,
            'instructions' => $question->instructions,
        ];

        if ($question instanceof ChoiceQuestion) {
            $definition['criteria'] = (object) $question->criteria;
        }

        if ($question instanceof ScoreQuestion) {
            $definition['criteria'] = $question->levels;
        }

        return $definition;
    }
}
