<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Decision;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * A call to the Jev decision endpoint: either inline questions or a saved judge.
 */
final readonly class DecisionRequest
{
    public const int MAX_LABEL_LENGTH = 256;

    public const int MAX_MODEL_LENGTH = 128;

    /**
     * Create the request. Use {@see forQuestions()} or {@see forJudge()}, which
     * guarantee that exactly one of `$questions` and `$judge` is set.
     *
     * Example:
     * ```php
     * DecisionRequest::forQuestions($state, $questions); // the constructor is private
     * ```
     *
     * @param  State  $state  What Jev decides on.
     * @param  QuestionSet|null  $questions  Inline questions, or null for a judge call.
     * @param  JudgeRef|null  $judge  Saved judge, or null for an inline call.
     * @param  string|null  $model  Model to use; null lets the application pick its default.
     * @param  string|null  $sessionId  Jev `session_id` label, at most 256 characters.
     * @param  string|null  $user  Jev `user` label, at most 256 characters.
     * @param  Trace  $trace  Labels stored privately by Jev.
     * @param  Context  $context  Local data attached to events, never sent to Jev.
     *
     * @throws InvalidValue When the model, session id or user is blank or too long.
     */
    private function __construct(
        public State $state,
        public ?QuestionSet $questions,
        public ?JudgeRef $judge,
        public ?string $model,
        public ?string $sessionId,
        public ?string $user,
        public Trace $trace,
        public Context $context,
    ) {
        Guard::nullableIdentifier($model, self::MAX_MODEL_LENGTH, 'model');
        Guard::nullableIdentifier($sessionId, self::MAX_LABEL_LENGTH, 'session id');
        Guard::nullableIdentifier($user, self::MAX_LABEL_LENGTH, 'user');
    }

    /**
     * Create a request with inline questions.
     *
     * Example:
     * ```php
     * DecisionRequest::forQuestions($ticket->body, QuestionSet::of(
     *     new NoulQuestion('is_urgent', 'Does this convey urgency?'),
     * ));
     * ```
     *
     * @param  State|string|array<array-key, mixed>  $state  What Jev decides on.
     * @param  QuestionSet  $questions  The questions to answer.
     * @return self The request, without model, labels, trace or context.
     *
     * @throws InvalidValue When the state is empty or holds objects.
     */
    public static function forQuestions(State|string|array $state, QuestionSet $questions): self
    {
        return new self(self::state($state), $questions, null, null, null, null, Trace::empty(), Context::empty());
    }

    /**
     * Create a request answered by a saved judge.
     *
     * Example:
     * ```php
     * DecisionRequest::forJudge($transcript, new JudgeRef('judge_123', 3));
     * ```
     *
     * @param  State|string|array<array-key, mixed>  $state  What Jev decides on.
     * @param  JudgeRef  $judge  The saved judge and optional revision.
     * @return self The request, without model, labels, trace or context.
     *
     * @throws InvalidValue When the state is empty or holds objects.
     */
    public static function forJudge(State|string|array $state, JudgeRef $judge): self
    {
        return new self(self::state($state), null, $judge, null, null, null, Trace::empty(), Context::empty());
    }

    /**
     * Whether the request uses a saved judge instead of inline questions.
     *
     * Example:
     * ```php
     * if ($request->isJudgeCall()) {
     *     $judgeId = $request->judge->id;
     * }
     * ```
     *
     * @return bool True for a judge call.
     */
    public function isJudgeCall(): bool
    {
        return $this->judge !== null;
    }

    /**
     * Return a copy using another model.
     *
     * Example:
     * ```php
     * $request = $request->withModel('laya-multilingual');
     * ```
     *
     * @param  string|null  $model  Model name, or null for the application default.
     * @return self A new request; the original is unchanged.
     *
     * @throws InvalidValue When the model is blank or longer than 128 characters.
     */
    public function withModel(?string $model): self
    {
        return new self($this->state, $this->questions, $this->judge, $model, $this->sessionId, $this->user, $this->trace, $this->context);
    }

    /**
     * Return a copy with another Jev `session_id` label.
     *
     * Example:
     * ```php
     * $request = $request->withSessionId("ticket-{$ticket->id}");
     * ```
     *
     * @param  string|null  $sessionId  Session label, or null to remove it.
     * @return self A new request; the original is unchanged.
     *
     * @throws InvalidValue When the label is blank or longer than 256 characters.
     */
    public function withSessionId(?string $sessionId): self
    {
        return new self($this->state, $this->questions, $this->judge, $this->model, $sessionId, $this->user, $this->trace, $this->context);
    }

    /**
     * Return a copy with another Jev `user` label.
     *
     * Example:
     * ```php
     * $request = $request->withUser((string) auth()->id());
     * ```
     *
     * @param  string|null  $user  User label, or null to remove it.
     * @return self A new request; the original is unchanged.
     *
     * @throws InvalidValue When the label is blank or longer than 256 characters.
     */
    public function withUser(?string $user): self
    {
        return new self($this->state, $this->questions, $this->judge, $this->model, $this->sessionId, $user, $this->trace, $this->context);
    }

    /**
     * Return a copy with another trace.
     *
     * Example:
     * ```php
     * $request = $request->withTrace(Trace::of(['ticket_id' => 42]));
     * ```
     *
     * @param  Trace  $trace  Labels stored privately by Jev.
     * @return self A new request; the original is unchanged.
     */
    public function withTrace(Trace $trace): self
    {
        return new self($this->state, $this->questions, $this->judge, $this->model, $this->sessionId, $this->user, $trace, $this->context);
    }

    /**
     * Return a copy with another local context.
     *
     * Example:
     * ```php
     * $request = $request->withContext(Context::of(['ticket_id' => 42]));
     * ```
     *
     * @param  Context  $context  Local data attached to events, never sent to Jev.
     * @return self A new request; the original is unchanged.
     */
    public function withContext(Context $context): self
    {
        return new self($this->state, $this->questions, $this->judge, $this->model, $this->sessionId, $this->user, $this->trace, $context);
    }

    /**
     * Normalize the accepted state inputs into a {@see State}.
     *
     * Example:
     * ```php
     * self::state('text');            // State::of('text')
     * self::state(State::of('text')); // returned as is
     * ```
     *
     * @param  State|string|array<array-key, mixed>  $state  A state or raw text/data.
     * @return State The state.
     *
     * @throws InvalidValue When raw text is blank, or raw data is empty or holds objects.
     */
    private static function state(State|string|array $state): State
    {
        return $state instanceof State ? $state : State::of($state);
    }
}
