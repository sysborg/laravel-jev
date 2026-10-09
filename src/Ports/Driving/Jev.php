<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Ports\Driving;

use Sysborg\LaravelJevai\Domain\Account\Balance;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Decision\PendingDecision;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;

/**
 * What applications call to use Jev. The `Jev` facade resolves to this contract,
 * so applications can also type-hint it and swap it in tests.
 *
 * Every call is validated, correlated, traced, measured, recorded and published
 * as events by the implementation; callers only see results or exceptions.
 */
interface Jev
{
    /**
     * Evaluate a decision synchronously.
     *
     * Example:
     * ```php
     * $result = $jev->decide(
     *     DecisionRequest::forQuestions($ticket->body, QuestionSet::of(
     *         new ChoiceQuestion('department', 'Which team?', ['billing' => '...', 'technical' => '...']),
     *     ))->withContext(Context::of(['ticket_id' => $ticket->id])),
     * );
     *
     * $result->choice('department')->choice; // 'billing'
     * ```
     *
     * @param  DecisionRequest  $request  Inline questions or saved judge, with optional labels.
     * @return DecisionResult Answers, usage, billing and metadata.
     *
     * @throws InvalidValue When the request breaks a documented Jev limit (checked before sending).
     * @throws JevException When the call fails after the configured retries.
     */
    public function decide(DecisionRequest $request): DecisionResult;

    /**
     * Queue a decision for background evaluation.
     *
     * The result is delivered through events carrying the returned correlation id
     * and the request context.
     *
     * Example:
     * ```php
     * $pending = $jev->queue($request->withContext(Context::of(['ticket_id' => $ticket->id])));
     *
     * // later, in a listener:
     * public function handle(DecisionSucceeded $event): void
     * {
     *     Ticket::find($event->context()->get('ticket_id'))->route($event->result);
     * }
     * ```
     *
     * @param  DecisionRequest  $request  Inline questions or saved judge, with optional labels.
     * @return PendingDecision The correlation id and queue time.
     *
     * @throws InvalidValue When the request breaks a documented Jev limit (checked before queueing).
     */
    public function queue(DecisionRequest $request): PendingDecision;

    /**
     * Answer a yes/no question with and without web evidence.
     *
     * Example:
     * ```php
     * $result = $jev->webContext(
     *     WebContextRequest::ask('Has OpenAI released GPT-6?')->withNumResults(6),
     * );
     *
     * $result->isYes();                  // true
     * $result->evidenceChangedDecision(); // true
     * ```
     *
     * @param  WebContextRequest  $request  The question, search options or own sources.
     * @return WebContextResult Decision, evidence, usage, latency, billing and metadata.
     *
     * @throws JevException When the call fails after the configured retries.
     */
    public function webContext(WebContextRequest $request): WebContextResult;

    /**
     * List the model names available to the account. Runs no inference.
     *
     * Example:
     * ```php
     * $jev->models(); // ['jev-latest', 'jev-1.13.0', 'laya-english', 'clef', ...]
     * ```
     *
     * @return list<string> The model names.
     *
     * @throws JevException When the call fails.
     */
    public function models(): array;

    /**
     * Read the remaining credits and paid input tokens. Runs no inference.
     *
     * Example:
     * ```php
     * $jev->balance()->creditsRemaining; // 42.0
     * ```
     *
     * @return Balance What is left on the account.
     *
     * @throws JevException When the call fails.
     */
    public function balance(): Balance;

    /**
     * Use another configured connection (API key, base URL, default model).
     *
     * Example:
     * ```php
     * $jev->connection('tenant-a')->decide($request);
     * ```
     *
     * @param  string|null  $name  Name under `jev.connections`, or null for the default.
     * @return self A client bound to that connection.
     *
     * @throws InvalidValue When no connection has that name.
     */
    public function connection(?string $name = null): self;
}
