<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Builder;

use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Decision\JudgeRef;
use Sysborg\LaravelJevai\Domain\Decision\PendingDecision;
use Sysborg\LaravelJevai\Domain\Decision\State;
use Sysborg\LaravelJevai\Domain\Decision\Trace;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Question\ChoiceQuestion;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\Question;
use Sysborg\LaravelJevai\Domain\Question\QuestionSet;
use Sysborg\LaravelJevai\Domain\Question\ScoreQuestion;
use Sysborg\LaravelJevai\Ports\Driving\Jev;

/**
 * Immutable fluent builder for decision requests.
 *
 * Every method returns a new builder, so a partially configured builder can
 * be reused safely.
 *
 * ```php
 * $result = Jev::model('jev-latest')
 *     ->state($ticket->body)
 *     ->noul('is_urgent', 'Does this convey urgency?')
 *     ->choice('department', 'Which team?', ['billing' => '...', 'technical' => '...'])
 *     ->session("ticket-{$ticket->id}")
 *     ->context(['ticket_id' => $ticket->id])
 *     ->evaluate();
 * ```
 *
 * @api
 */
final readonly class DecisionBuilder
{
    /**
     * Create the builder. Use {@see for()} or the `Jev` facade.
     *
     * Example:
     * ```php
     * DecisionBuilder::for($jev); // the constructor is private
     * ```
     *
     * @param  Jev  $client  Evaluates or queues the built request.
     * @param  string|null  $model  Model name, or null for the connection default.
     * @param  State|null  $state  What Jev decides on.
     * @param  list<Question>  $questions  Inline questions, in order.
     * @param  JudgeRef|null  $judge  Saved judge, instead of questions.
     * @param  string|null  $session  Jev `session_id` label.
     * @param  string|null  $user  Jev `user` label.
     * @param  array<string, mixed>  $trace  Fields for Jev's private trace.
     * @param  array<string, mixed>  $context  Local data attached to events.
     */
    private function __construct(
        private Jev $client,
        private ?string $model = null,
        private ?State $state = null,
        private array $questions = [],
        private ?JudgeRef $judge = null,
        private ?string $session = null,
        private ?string $user = null,
        private array $trace = [],
        private array $context = [],
    ) {}

    /**
     * Start an empty builder.
     *
     * Example:
     * ```php
     * DecisionBuilder::for(app(Jev::class))->state('...')->noul('q', 'Q?')->evaluate();
     * ```
     *
     * @param  Jev  $client  Evaluates or queues the built request.
     * @return self The builder.
     */
    public static function for(Jev $client): self
    {
        return new self($client);
    }

    /**
     * Use a model.
     *
     * Example:
     * ```php
     * $builder->model('laya-multilingual');
     * ```
     *
     * @param  string|null  $model  Model name, or null for the connection default.
     * @return self A new builder.
     */
    public function model(?string $model): self
    {
        return $this->copy(model: $model);
    }

    /**
     * Set what Jev decides on.
     *
     * Example:
     * ```php
     * $builder->state('My card was charged twice!');
     * $builder->state(['subject' => 'Refund', 'body' => '...']);
     * ```
     *
     * @param  State|string|array<array-key, mixed>  $state  Text, structured data or a {@see State}.
     * @return self A new builder.
     *
     * @throws InvalidValue When the text is blank or the data is empty or holds objects.
     */
    public function state(State|string|array $state): self
    {
        return $this->copy(state: $state instanceof State ? $state : State::of($state));
    }

    /**
     * Add a yes/no question.
     *
     * Example:
     * ```php
     * $builder->noul('is_urgent', 'Does this convey urgency?');
     * ```
     *
     * @param  string  $id  Question id, at most 64 characters.
     * @param  string  $instructions  What Jev should decide.
     * @return self A new builder.
     *
     * @throws InvalidValue When the id or instructions are invalid.
     */
    public function noul(string $id, string $instructions): self
    {
        return $this->question(new NoulQuestion($id, $instructions));
    }

    /**
     * Add a choice question.
     *
     * Example:
     * ```php
     * $builder->choice('department', 'Which team?', ['billing' => 'Payments', 'technical' => 'Bugs']);
     * ```
     *
     * @param  string  $id  Question id, at most 64 characters.
     * @param  string  $instructions  What Jev should decide.
     * @param  array<array-key, string>  $criteria  Option name => description, 2–255 options.
     * @return self A new builder.
     *
     * @throws InvalidValue When the id, instructions or options are invalid.
     */
    public function choice(string $id, string $instructions, array $criteria): self
    {
        return $this->question(new ChoiceQuestion($id, $instructions, $criteria));
    }

    /**
     * Add a score question.
     *
     * Example:
     * ```php
     * $builder->score('frustration', 'How frustrated?', ['Calm', 'Frustrated', 'Very angry']);
     * ```
     *
     * @param  string  $id  Question id, at most 64 characters.
     * @param  string  $instructions  What Jev should rate.
     * @param  list<string>  $levels  Labels from lowest to highest, 2–10 levels.
     * @return self A new builder.
     *
     * @throws InvalidValue When the id, instructions or levels are invalid.
     */
    public function score(string $id, string $instructions, array $levels): self
    {
        return $this->question(new ScoreQuestion($id, $instructions, $levels));
    }

    /**
     * Add any question object.
     *
     * Example:
     * ```php
     * $builder->question(new NoulQuestion('is_spam', 'Is this spam?'));
     * ```
     *
     * @param  Question  $question  The question.
     * @return self A new builder.
     */
    public function question(Question $question): self
    {
        return $this->copy(questions: [...$this->questions, $question]);
    }

    /**
     * Answer with a saved judge instead of inline questions.
     *
     * Example:
     * ```php
     * $builder->judge('judge_123', revision: 3);
     * ```
     *
     * @param  string  $judgeId  The judge id from the Jev app.
     * @param  int|null  $revision  Revision to pin, or null for the latest rules.
     * @return self A new builder.
     *
     * @throws InvalidValue When the id is blank or the revision is lower than 1.
     */
    public function judge(string $judgeId, ?int $revision = null): self
    {
        return $this->copy(judge: new JudgeRef($judgeId, $revision));
    }

    /**
     * Set the Jev `session_id` label.
     *
     * Example:
     * ```php
     * $builder->session("ticket-{$ticket->id}");
     * ```
     *
     * @param  string|null  $sessionId  The label, or null to let the correlation id be used.
     * @return self A new builder.
     */
    public function session(?string $sessionId): self
    {
        return $this->copy(session: $sessionId);
    }

    /**
     * Set the Jev `user` label.
     *
     * Example:
     * ```php
     * $builder->user((string) auth()->id());
     * ```
     *
     * @param  string|null  $user  The label, or null for none.
     * @return self A new builder.
     */
    public function user(?string $user): self
    {
        return $this->copy(user: $user);
    }

    /**
     * Add fields to Jev's private trace.
     *
     * Example:
     * ```php
     * $builder->trace(['ticket_id' => 42])->trace('pipeline', 'triage-v2');
     * ```
     *
     * @param  array<string, mixed>|string  $key  A field name, or several fields at once.
     * @param  mixed  $value  The value, when `$key` is a field name.
     * @return self A new builder.
     */
    public function trace(array|string $key, mixed $value = null): self
    {
        return $this->copy(trace: [...$this->trace, ...(is_array($key) ? $key : [$key => $value])]);
    }

    /**
     * Add local data attached to every event of the call (never sent to Jev).
     *
     * Example:
     * ```php
     * $builder->context(['ticket_id' => $ticket->id]);
     * ```
     *
     * @param  array<string, mixed>|string  $key  A key, or several values at once.
     * @param  mixed  $value  The value, when `$key` is a key.
     * @return self A new builder.
     */
    public function context(array|string $key, mixed $value = null): self
    {
        return $this->copy(context: [...$this->context, ...(is_array($key) ? $key : [$key => $value])]);
    }

    /**
     * Build the request.
     *
     * Example:
     * ```php
     * $request = Jev::state($text)->noul('q', 'Q?')->toRequest();
     * ```
     *
     * @return DecisionRequest The request.
     *
     * @throws InvalidValue When the state is missing, there are neither questions nor a judge,
     *                      both are set, or a label, trace or context value is invalid.
     */
    public function toRequest(): DecisionRequest
    {
        $state = $this->state ?? throw InvalidValue::because('decision builder state', 'must be set with state()');

        $request = match (true) {
            $this->judge !== null && $this->questions !== [] => throw InvalidValue::because(
                'decision builder',
                'must use either questions or a judge, not both',
            ),
            $this->judge !== null => DecisionRequest::forJudge($state, $this->judge),
            $this->questions !== [] => DecisionRequest::forQuestions($state, new QuestionSet(...$this->questions)),
            default => throw InvalidValue::because('decision builder', 'needs at least one question or a judge'),
        };

        return $request
            ->withModel($this->model)
            ->withSessionId($this->session)
            ->withUser($this->user)
            ->withTrace(Trace::of($this->trace))
            ->withContext(Context::of($this->context));
    }

    /**
     * Build and evaluate the request synchronously.
     *
     * Example:
     * ```php
     * $result = $builder->evaluate();
     * $result->choice('department')->choice; // 'billing'
     * ```
     *
     * @return DecisionResult Answers, usage, billing and metadata.
     *
     * @throws InvalidValue When the request is incomplete or breaks a Jev limit.
     * @throws JevException When the call fails after the configured retries.
     */
    public function evaluate(): DecisionResult
    {
        return $this->client->decide($this->toRequest());
    }

    /**
     * Build and queue the request; the result arrives through events.
     *
     * Example:
     * ```php
     * $pending = $builder->context(['ticket_id' => 42])->queue();
     * ```
     *
     * @return PendingDecision The correlation id, queue time and context.
     *
     * @throws InvalidValue When the request is incomplete or breaks a Jev limit.
     */
    public function queue(): PendingDecision
    {
        return $this->client->queue($this->toRequest());
    }

    /**
     * Copy the builder with some fields changed.
     *
     * Example:
     * ```php
     * $this->copy(model: 'clef');
     * ```
     *
     * @param  string|null|false  $model  New model, or false to keep the current one.
     * @param  State|null|false  $state  New state, or false to keep the current one.
     * @param  list<Question>|null  $questions  New questions, or null to keep the current ones.
     * @param  JudgeRef|null|false  $judge  New judge, or false to keep the current one.
     * @param  string|null|false  $session  New session label, or false to keep the current one.
     * @param  string|null|false  $user  New user label, or false to keep the current one.
     * @param  array<string, mixed>|null  $trace  New trace fields, or null to keep the current ones.
     * @param  array<string, mixed>|null  $context  New context, or null to keep the current one.
     * @return self The new builder.
     */
    private function copy(
        string|null|false $model = false,
        State|null|false $state = false,
        ?array $questions = null,
        JudgeRef|null|false $judge = false,
        string|null|false $session = false,
        string|null|false $user = false,
        ?array $trace = null,
        ?array $context = null,
    ): self {
        return new self(
            $this->client,
            $model === false ? $this->model : $model,
            $state === false ? $this->state : $state,
            $questions ?? $this->questions,
            $judge === false ? $this->judge : $judge,
            $session === false ? $this->session : $session,
            $user === false ? $this->user : $user,
            $trace ?? $this->trace,
            $context ?? $this->context,
        );
    }
}
