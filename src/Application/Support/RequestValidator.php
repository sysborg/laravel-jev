<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Support;

use stdClass;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Question\ChoiceQuestion;
use Sysborg\LaravelJevai\Domain\Question\ScoreQuestion;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;

/**
 * Checks, before any network call, the Jev limits that value objects cannot check alone.
 *
 * Per-field limits (question counts, id lengths, trace size, questions/judge XOR,
 * result counts...) are enforced by the domain value objects on construction;
 * this validator adds the limit on the whole request body.
 */
final readonly class RequestValidator
{
    public const int MAX_BODY_BYTES = 256_000;

    /**
     * Create the validator.
     *
     * Example:
     * ```php
     * new RequestValidator(defaultModel: 'jev-latest');
     * ```
     *
     * @param  string  $defaultModel  Model assumed when a request does not name one.
     * @param  int  $maxBodyBytes  Largest accepted JSON body.
     */
    public function __construct(
        private string $defaultModel = 'jev-latest',
        private int $maxBodyBytes = self::MAX_BODY_BYTES,
    ) {}

    /**
     * Validate a request.
     *
     * Example:
     * ```php
     * $validator->validate($request); // throws when the body would exceed 256,000 bytes
     * ```
     *
     * @param  DecisionRequest|WebContextRequest  $request  The request about to be sent.
     * @return void Nothing.
     *
     * @throws InvalidValue When the JSON body would be larger than the limit.
     */
    public function validate(DecisionRequest|WebContextRequest $request): void
    {
        if ($request instanceof WebContextRequest) {
            return;
        }

        $bytes = $this->estimateBytes($request);

        if ($bytes > $this->maxBodyBytes) {
            throw InvalidValue::because(
                'request body',
                "must be at most {$this->maxBodyBytes} bytes once JSON encoded, got {$bytes}",
            );
        }
    }

    /**
     * Size of the JSON body the HTTP adapter will send, encoded the same way.
     *
     * Example:
     * ```php
     * $validator->estimateBytes($request); // 1834
     * ```
     *
     * @param  DecisionRequest  $request  The request.
     * @return int Bytes.
     */
    public function estimateBytes(DecisionRequest $request): int
    {
        return strlen((string) json_encode($this->payload($request), JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * The body as sent by `POST /v1/systemone`, mirroring the HTTP adapter.
     *
     * Example:
     * ```php
     * $this->payload($request); // ['model' => 'jev-latest', 'state' => '...', 'questions' => {...}]
     * ```
     *
     * @param  DecisionRequest  $request  The request.
     * @return array<string, mixed> The body.
     */
    private function payload(DecisionRequest $request): array
    {
        $payload = ['model' => $request->model ?? $this->defaultModel, 'state' => $request->state->value];

        if ($request->questions !== null) {
            $questions = new stdClass;

            foreach ($request->questions as $id => $question) {
                $definition = ['type' => $question->type()->value, 'instructions' => $question->instructions];

                if ($question instanceof ChoiceQuestion) {
                    $definition['criteria'] = (object) $question->criteria;
                }

                if ($question instanceof ScoreQuestion) {
                    $definition['criteria'] = $question->levels;
                }

                $questions->{$id} = $definition;
            }

            $payload['questions'] = $questions;
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
}
