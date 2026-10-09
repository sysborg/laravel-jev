<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application;

use Sysborg\LaravelJevai\Application\Builder\DecisionBuilder;
use Sysborg\LaravelJevai\Application\Builder\WebContextBuilder;
use Sysborg\LaravelJevai\Application\Support\RequestValidator;
use Sysborg\LaravelJevai\Application\UseCases\EvaluateDecision;
use Sysborg\LaravelJevai\Application\UseCases\GetBalance;
use Sysborg\LaravelJevai\Application\UseCases\ListModels;
use Sysborg\LaravelJevai\Application\UseCases\ResolveWebContext;
use Sysborg\LaravelJevai\Domain\Account\Balance;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Decision\PendingDecision;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Ports\Driven\Clock;
use Sysborg\LaravelJevai\Ports\Driven\DecisionQueue;
use Sysborg\LaravelJevai\Ports\Driven\IdGenerator;
use Sysborg\LaravelJevai\Ports\Driving\Jev;

/**
 * The package's implementation of the {@see Jev} contract, plus fluent builders.
 *
 * Resolved by the `Jev` facade.
 */
final readonly class JevClient implements Jev
{
    /**
     * Create the client.
     *
     * Example:
     * ```php
     * app(Jev::class); // built by the service provider
     * ```
     *
     * @param  EvaluateDecision  $evaluate  Decision use case.
     * @param  ResolveWebContext  $resolveWebContext  Web-context use case.
     * @param  ListModels  $listModels  Model listing use case.
     * @param  GetBalance  $getBalance  Balance use case.
     * @param  DecisionQueue  $queue  Defers decisions to background workers.
     * @param  RequestValidator  $validator  Checks queued requests before they are queued.
     * @param  IdGenerator  $ids  Creates correlation ids for queued decisions.
     * @param  Clock  $clock  Timestamps queued decisions.
     */
    public function __construct(
        private EvaluateDecision $evaluate,
        private ResolveWebContext $resolveWebContext,
        private ListModels $listModels,
        private GetBalance $getBalance,
        private DecisionQueue $queue,
        private RequestValidator $validator,
        private IdGenerator $ids,
        private Clock $clock,
    ) {}

    /**
     * Evaluate a decision synchronously.
     *
     * Example:
     * ```php
     * $result = Jev::decide(DecisionRequest::forQuestions($text, $questions));
     * ```
     *
     * @param  DecisionRequest  $request  Inline questions or saved judge, with optional labels.
     * @return DecisionResult Answers, usage, billing and metadata.
     *
     * @throws InvalidValue When the request breaks a documented Jev limit (checked before sending).
     * @throws JevException When the call fails after the configured retries.
     */
    public function decide(DecisionRequest $request): DecisionResult
    {
        return $this->evaluate->execute($request);
    }

    /**
     * Queue a decision; the result arrives through events carrying the returned correlation id.
     *
     * Example:
     * ```php
     * $pending = Jev::queue($request->withContext(Context::of(['ticket_id' => 42])));
     * ```
     *
     * @param  DecisionRequest  $request  Inline questions or saved judge, with optional labels.
     * @return PendingDecision The correlation id, queue time and context.
     *
     * @throws InvalidValue When the request breaks a documented Jev limit (checked before queueing).
     */
    public function queue(DecisionRequest $request): PendingDecision
    {
        $this->validator->validate($request);

        $correlationId = $this->ids->correlationId();
        $this->queue->push($request, $correlationId);

        return new PendingDecision($correlationId, $this->clock->now(), $request->context);
    }

    /**
     * Answer a yes/no question with and without web evidence.
     *
     * Example:
     * ```php
     * Jev::resolveWebContext(WebContextRequest::ask('Has GPT-6 been released?'))->isYes();
     * ```
     *
     * @param  WebContextRequest  $request  The question, search options or own sources.
     * @return WebContextResult Decision, evidence, usage, latency, billing and metadata.
     *
     * @throws InvalidValue When the request breaks a documented Jev limit.
     * @throws JevException When the call fails after the configured retries.
     */
    public function resolveWebContext(WebContextRequest $request): WebContextResult
    {
        return $this->resolveWebContext->execute($request);
    }

    /**
     * List the model names available to the account.
     *
     * Example:
     * ```php
     * Jev::models(); // ['jev-latest', 'clef', ...]
     * ```
     *
     * @return list<string> The model names.
     *
     * @throws JevException When the call fails.
     */
    public function models(): array
    {
        return $this->listModels->execute();
    }

    /**
     * Read the remaining credits and paid input tokens.
     *
     * Example:
     * ```php
     * Jev::balance()->paidInputTokensRemaining;
     * ```
     *
     * @return Balance What is left on the account.
     *
     * @throws JevException When the call fails.
     */
    public function balance(): Balance
    {
        return $this->getBalance->execute();
    }

    /**
     * Start an empty decision builder.
     *
     * Example:
     * ```php
     * Jev::request()->state($text)->noul('is_urgent', 'Urgent?')->evaluate();
     * ```
     *
     * @return DecisionBuilder The builder.
     */
    public function request(): DecisionBuilder
    {
        return DecisionBuilder::for($this);
    }

    /**
     * Start a decision builder with a model.
     *
     * Example:
     * ```php
     * Jev::model('clef')->state($text)->choice('department', 'Which team?', $teams)->evaluate();
     * ```
     *
     * @param  string  $model  Model name.
     * @return DecisionBuilder The builder.
     */
    public function model(string $model): DecisionBuilder
    {
        return $this->request()->model($model);
    }

    /**
     * Start a decision builder with the state to decide on.
     *
     * Example:
     * ```php
     * Jev::state($ticket->body)->noul('is_urgent', 'Does this convey urgency?')->evaluate();
     * ```
     *
     * @param  string|array<array-key, mixed>  $state  Text or structured data.
     * @return DecisionBuilder The builder.
     */
    public function state(string|array $state): DecisionBuilder
    {
        return $this->request()->state($state);
    }

    /**
     * Start a decision builder answered by a saved judge.
     *
     * Example:
     * ```php
     * Jev::judge('judge_123', revision: 3)->state($transcript)->evaluate();
     * ```
     *
     * @param  string  $judgeId  The judge id from the Jev app.
     * @param  int|null  $revision  Revision to pin, or null for the latest rules.
     * @return DecisionBuilder The builder.
     */
    public function judge(string $judgeId, ?int $revision = null): DecisionBuilder
    {
        return $this->request()->judge($judgeId, $revision);
    }

    /**
     * Start a web-context builder for a yes/no question.
     *
     * Example:
     * ```php
     * Jev::webContext('Has OpenAI released GPT-6?')->numResults(6)->resolve();
     * ```
     *
     * @param  string  $question  The yes/no question.
     * @return WebContextBuilder The builder.
     *
     * @throws InvalidValue When the question is blank.
     */
    public function webContext(string $question): WebContextBuilder
    {
        return WebContextBuilder::for($this, $question);
    }
}
