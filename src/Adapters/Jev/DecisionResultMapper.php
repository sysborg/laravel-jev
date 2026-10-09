<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use Illuminate\Http\Client\Response;
use Sysborg\LaravelJevai\Domain\Answer\Answer;
use Sysborg\LaravelJevai\Domain\Answer\AnswerSet;
use Sysborg\LaravelJevai\Domain\Answer\ChoiceAnswer;
use Sysborg\LaravelJevai\Domain\Answer\NoulAnswer;
use Sysborg\LaravelJevai\Domain\Answer\ScoreAnswer;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\UnexpectedResponse;
use Sysborg\LaravelJevai\Domain\Question\QuestionType;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Run\RunMetadata;
use Sysborg\LaravelJevai\Domain\Usage\Usage;

/**
 * Maps a successful `POST /v1/systemone` response into a {@see DecisionResult}.
 */
final class DecisionResultMapper
{
    /**
     * Map the response.
     *
     * Example:
     * ```php
     * $result = DecisionResultMapper::map($response, $request, $correlationId, $connection, latencyMs: 412);
     * ```
     *
     * @param  Response  $response  A 2xx response.
     * @param  DecisionRequest  $request  The request that was sent, to resolve answer types and the model.
     * @param  CorrelationId  $correlationId  Id to stamp on the metadata.
     * @param  JevConnection  $connection  Connection used, for its name and default model.
     * @param  int  $latencyMs  Duration of this single attempt.
     * @return DecisionResult Answers, usage, billing, metadata and the decoded body as `raw`.
     *
     * @throws UnexpectedResponse When the body, a header or an answer cannot be understood.
     */
    public static function map(
        Response $response,
        DecisionRequest $request,
        CorrelationId $correlationId,
        JevConnection $connection,
        int $latencyMs,
    ): DecisionResult {
        $body = ResponseReader::fromResponse($response);

        try {
            return new DecisionResult(
                answers: self::answers($body->object('answers'), $request),
                usage: self::usage($body),
                billing: BillingMapper::map($response, $body),
                meta: new RunMetadata(
                    correlationId: $correlationId,
                    model: $body->optionalString('model') ?? $request->model ?? $connection->model,
                    latencyMs: $latencyMs,
                    responseId: $body->optionalString('id'),
                    runId: BillingMapper::header($response, 'X-Jev-Run-Id'),
                    provider: $body->optionalString('provider'),
                    judgeId: BillingMapper::header($response, 'X-Jev-Judge-Id') ?? $request->judge?->id,
                    judgeRevision: BillingMapper::intHeader($response, 'X-Jev-Judge-Revision') ?? $request->judge?->revision,
                    connection: $connection->name,
                ),
                raw: self::raw($body),
            );
        } catch (InvalidValue $e) {
            throw new UnexpectedResponse('Jev returned a value outside its documented range: '.$e->getMessage(), $response->status(), $e);
        }
    }

    /**
     * Map every answer of the `answers` object.
     *
     * Example:
     * ```php
     * self::answers($body->object('answers'), $request); // AnswerSet
     * ```
     *
     * @param  ResponseReader  $answers  The `answers` object.
     * @param  DecisionRequest  $request  The request, to infer types Jev did not repeat.
     * @return AnswerSet The answers.
     *
     * @throws UnexpectedResponse When an answer cannot be understood.
     * @throws InvalidValue When an answer value is outside its documented range.
     */
    private static function answers(ResponseReader $answers, DecisionRequest $request): AnswerSet
    {
        $mapped = [];

        foreach ($answers->keys() as $id) {
            $mapped[] = self::answer($id, $answers->object($id), $request->questions?->get($id)?->type());
        }

        return new AnswerSet(...$mapped);
    }

    /**
     * Map one answer according to its type.
     *
     * Example:
     * ```php
     * self::answer('is_urgent', $reader, QuestionType::Noul); // NoulAnswer
     * ```
     *
     * @param  string  $id  Question id.
     * @param  ResponseReader  $answer  The answer object.
     * @param  QuestionType|null  $expected  Type of the asked question, when known.
     * @return Answer The typed answer.
     *
     * @throws UnexpectedResponse When the type is unknown or a required field is missing.
     * @throws InvalidValue When a value is outside its documented range.
     */
    private static function answer(string $id, ResponseReader $answer, ?QuestionType $expected): Answer
    {
        $type = QuestionType::tryFrom($answer->optionalString('type') ?? '') ?? $expected
            ?? throw $answer->invalid('type', 'one of "noul", "choice" or "score"');

        return match ($type) {
            QuestionType::Noul => new NoulAnswer($id, $answer->float('noul')),
            QuestionType::Choice => new ChoiceAnswer(
                $id,
                $answer->string('choice'),
                $answer->optionalFloat('confidence'),
                $answer->optionalNumberMap('probabilities'),
            ),
            QuestionType::Score => new ScoreAnswer(
                $id,
                $answer->float('score'),
                $answer->optionalFloat('confidence'),
                self::levelKeys($answer->optionalStringMap('legend')),
                self::levelKeys($answer->optionalNumberMap('probabilities')),
            ),
        };
    }

    /**
     * Turn the string level keys of a score (`"0"`, `"1"`) into integers.
     *
     * Example:
     * ```php
     * self::levelKeys(['0' => 'Calm', '1' => 'Angry']); // [0 => 'Calm', 1 => 'Angry']
     * ```
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $map  Level => value.
     * @return array<int, TValue> The same map with integer keys.
     */
    private static function levelKeys(array $map): array
    {
        $levels = [];

        foreach ($map as $level => $value) {
            $levels[(int) $level] = $value;
        }

        return $levels;
    }

    /**
     * Read `usage`; a missing object means Jev reported no usage.
     *
     * Example:
     * ```php
     * self::usage($body); // Usage(120, 8)
     * ```
     *
     * @param  ResponseReader  $body  The decoded body.
     * @return Usage Input and output tokens; cost stays unknown (Jev omits it).
     *
     * @throws UnexpectedResponse When a usage field has the wrong type.
     * @throws InvalidValue When a token count is negative.
     */
    private static function usage(ResponseReader $body): Usage
    {
        $usage = $body->optionalObject('usage');

        return new Usage(
            $usage?->optionalInt('input_tokens') ?? 0,
            $usage?->optionalInt('output_tokens') ?? 0,
            $usage?->optionalFloat('cost'),
        );
    }

    /**
     * The decoded body with string keys, kept as the result's `raw`.
     *
     * Example:
     * ```php
     * self::raw($body); // ['model' => 'jev-latest', 'answers' => [...], ...]
     * ```
     *
     * @param  ResponseReader  $body  The decoded body.
     * @return array<string, mixed> The body.
     */
    private static function raw(ResponseReader $body): array
    {
        $raw = [];

        foreach ($body->toArray() as $key => $value) {
            $raw[(string) $key] = $value;
        }

        return $raw;
    }
}
