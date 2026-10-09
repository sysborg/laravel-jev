<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Testing;

use Closure;
use PHPUnit\Framework\Assert as PHPUnit;
use Sysborg\LaravelJevai\Domain\Account\Balance;
use Sysborg\LaravelJevai\Domain\Answer\Answer;
use Sysborg\LaravelJevai\Domain\Answer\AnswerSet;
use Sysborg\LaravelJevai\Domain\Answer\ChoiceAnswer;
use Sysborg\LaravelJevai\Domain\Answer\NoulAnswer;
use Sysborg\LaravelJevai\Domain\Answer\ScoreAnswer;
use Sysborg\LaravelJevai\Domain\Decision\DecisionRequest;
use Sysborg\LaravelJevai\Domain\Decision\DecisionResult;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Question\ChoiceQuestion;
use Sysborg\LaravelJevai\Domain\Question\NoulQuestion;
use Sysborg\LaravelJevai\Domain\Question\Question;
use Sysborg\LaravelJevai\Domain\Question\ScoreQuestion;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Run\RunMetadata;
use Sysborg\LaravelJevai\Domain\Usage\Billing;
use Sysborg\LaravelJevai\Domain\Usage\BillingMode;
use Sysborg\LaravelJevai\Domain\Usage\Usage;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextLatency;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextUsage;
use Sysborg\LaravelJevai\Ports\Driven\AccountGateway;
use Sysborg\LaravelJevai\Ports\Driven\DecisionGateway;
use Sysborg\LaravelJevai\Ports\Driven\DecisionQueue;
use Sysborg\LaravelJevai\Ports\Driven\WebContextGateway;

/**
 * In-memory Jev for tests, installed with `Jev::fake()`.
 *
 * It replaces the gateways only: the real pipeline still runs, so events,
 * usage records, metrics and retries behave exactly as in production.
 *
 * ```php
 * $fake = Jev::fake(['department' => 'billing', 'is_urgent' => true])->withUsage(input: 120);
 *
 * // ... code under test ...
 *
 * Jev::assertEvaluated(fn (DecisionRequest $request) => $request->questions?->has('department'));
 * Jev::assertTokensUsedLessThan(500);
 * ```
 */
final class JevFake implements AccountGateway, DecisionGateway, DecisionQueue, WebContextGateway
{
    /** @var array<string, mixed> Question id => fake answer value. */
    private array $answers;

    /** @var list<array<string, mixed>|JevException|Closure(DecisionRequest, CorrelationId): DecisionResult> */
    private array $sequence = [];

    /** @var list<array{request: DecisionRequest, result: DecisionResult|null}> */
    private array $decisions = [];

    /** @var list<WebContextRequest> */
    private array $webContexts = [];

    /** @var list<array{request: DecisionRequest, correlationId: CorrelationId}> */
    private array $queued = [];

    private Usage $usage;

    private Billing $billing;

    private string $webDecision = 'yes';

    private float $webConfidence = 0.9;

    /** @var list<string> */
    private array $models = ['jev-latest', 'jev-preview'];

    private Balance $balance;

    /**
     * Create the fake.
     *
     * Example:
     * ```php
     * new JevFake(['department' => 'billing', 'is_urgent' => 0.92, 'frustration' => 2]);
     * ```
     *
     * @param  array<string, mixed>  $answers  Question id => answer for every call:
     *                                         noul: bool or 0..1 float; choice: option name; score: level;
     *                                         or an {@see Answer}. Unlisted questions get a neutral default.
     */
    public function __construct(array $answers = [])
    {
        $this->answers = $answers;
        $this->usage = new Usage(100, 4);
        $this->billing = new Billing(BillingMode::Tokens, 100, 0.0, 1_000_000);
        $this->balance = new Balance(100.0, 1_000_000);
    }

    /**
     * Set the default answers of every call.
     *
     * Example:
     * ```php
     * Jev::fake()->answer(['is_spam' => false]);
     * ```
     *
     * @param  array<string, mixed>  $answers  Question id => answer (see the constructor).
     * @return self The fake.
     */
    public function answer(array $answers): self
    {
        $this->answers = [...$this->answers, ...$answers];

        return $this;
    }

    /**
     * Queue per-call responses, used in order before falling back to the default answers.
     *
     * Example:
     * ```php
     * Jev::fake()->sequence(
     *     ['department' => 'billing'],
     *     new UpstreamFailure(503),           // retried by the pipeline
     *     ['department' => 'technical'],
     *     fn (DecisionRequest $r, CorrelationId $id) => $customResult,
     * );
     * ```
     *
     * @param  array<string, mixed>|JevException|Closure(DecisionRequest, CorrelationId): DecisionResult  ...$responses  Answers, a failure to throw, or a result factory.
     * @return self The fake.
     */
    public function sequence(array|JevException|Closure ...$responses): self
    {
        $this->sequence = [...$this->sequence, ...array_values($responses)];

        return $this;
    }

    /**
     * Make the next attempts fail (one exception per attempt).
     *
     * Example:
     * ```php
     * Jev::fake()->failWith(new RateLimited(2));           // retried, then succeeds
     * Jev::fake()->failWith(new InsufficientCredits);      // not retried
     * ```
     *
     * @param  JevException  ...$failures  One failure per attempt, in order.
     * @return self The fake.
     */
    public function failWith(JevException ...$failures): self
    {
        return $this->sequence(...$failures);
    }

    /**
     * Set the token usage of every fake result.
     *
     * Example:
     * ```php
     * Jev::fake()->withUsage(input: 120, output: 8);
     * ```
     *
     * @param  int  $input  Input tokens.
     * @param  int  $output  Output tokens.
     * @return self The fake.
     *
     * @throws InvalidValue When a count is negative.
     */
    public function withUsage(int $input, int $output = 0): self
    {
        $this->usage = new Usage($input, $output);

        return $this;
    }

    /**
     * Set the billing of every fake result.
     *
     * Example:
     * ```php
     * Jev::fake()->withBilling(BillingMode::CreditsFallback, creditsCharged: 1.0, tokensRemaining: 0);
     * ```
     *
     * @param  BillingMode  $mode  How the call was paid.
     * @param  int  $inputTokensCharged  Paid input tokens charged.
     * @param  float  $creditsCharged  Credits charged.
     * @param  int|null  $tokensRemaining  Tokens left (drives BalanceLow).
     * @return self The fake.
     *
     * @throws InvalidValue When a value is negative.
     */
    public function withBilling(BillingMode $mode, int $inputTokensCharged = 0, float $creditsCharged = 0.0, ?int $tokensRemaining = null): self
    {
        $this->billing = new Billing($mode, $inputTokensCharged, $creditsCharged, $tokensRemaining);

        return $this;
    }

    /**
     * Set the web-context decision.
     *
     * Example:
     * ```php
     * Jev::fake()->webContextAnswer('no', 0.75);
     * ```
     *
     * @param  string  $decision  "yes" or "no".
     * @param  float  $confidence  Confidence, 0..1.
     * @return self The fake.
     */
    public function webContextAnswer(string $decision, float $confidence = 0.9): self
    {
        $this->webDecision = $decision;
        $this->webConfidence = $confidence;

        return $this;
    }

    /**
     * Set what `Jev::models()` and `Jev::balance()` return.
     *
     * Example:
     * ```php
     * Jev::fake()->withAccount(['jev-latest', 'clef'], credits: 0, tokens: 0);
     * ```
     *
     * @param  list<string>  $models  Model names.
     * @param  float  $credits  Credits remaining.
     * @param  int  $tokens  Paid input tokens remaining.
     * @return self The fake.
     *
     * @throws InvalidValue When a balance is negative.
     */
    public function withAccount(array $models, float $credits = 100.0, int $tokens = 1_000_000): self
    {
        $this->models = $models;
        $this->balance = new Balance($credits, $tokens);

        return $this;
    }

    /**
     * Answer a decision attempt (DecisionGateway).
     *
     * Example:
     * ```php
     * $fake->decide($request, new CorrelationId('c-1'));
     * ```
     *
     * @param  DecisionRequest  $request  The request.
     * @param  CorrelationId  $correlationId  The correlation id.
     * @return DecisionResult The fake result.
     *
     * @throws JevException When the next sequence item is a failure.
     * @throws InvalidValue When a configured answer does not fit its question.
     */
    public function decide(DecisionRequest $request, CorrelationId $correlationId): DecisionResult
    {
        $next = array_shift($this->sequence);

        if ($next instanceof JevException) {
            $this->decisions[] = ['request' => $request, 'result' => null];

            throw $next;
        }

        $result = $next instanceof Closure
            ? $next($request, $correlationId)
            : $this->result($request, $correlationId, [...$this->answers, ...($next ?? [])]);

        $this->decisions[] = ['request' => $request, 'result' => $result];

        return $result;
    }

    /**
     * Answer a web-context attempt (WebContextGateway).
     *
     * Example:
     * ```php
     * $fake->resolve(WebContextRequest::ask('Q?'), new CorrelationId('c-1'))->isYes();
     * ```
     *
     * @param  WebContextRequest  $request  The request.
     * @param  CorrelationId  $correlationId  The correlation id.
     * @return WebContextResult The fake result.
     *
     * @throws JevException When the next sequence item is a failure.
     */
    public function resolve(WebContextRequest $request, CorrelationId $correlationId): WebContextResult
    {
        $this->webContexts[] = $request;
        $next = $this->sequence[0] ?? null;

        if ($next instanceof JevException) {
            array_shift($this->sequence);

            throw $next;
        }

        return new WebContextResult(
            $this->webDecision,
            $this->webConfidence,
            new ChoiceAnswer('with_web', $this->webDecision, $this->webConfidence),
            new ChoiceAnswer('without_web', $this->webDecision),
            [],
            null,
            new WebContextUsage($this->usage->inputTokens, $this->usage->outputTokens, 2, ! $request->usesOwnSources()),
            new WebContextLatency(0, 0),
            new RunMetadata($correlationId, 'jev-latest', 0, connection: 'fake'),
            $this->billing,
        );
    }

    /**
     * The fake model list (AccountGateway).
     *
     * Example:
     * ```php
     * Jev::models(); // ['jev-latest', 'jev-preview']
     * ```
     *
     * @return list<string> The models.
     */
    public function models(): array
    {
        return $this->models;
    }

    /**
     * The fake balance (AccountGateway).
     *
     * Example:
     * ```php
     * Jev::balance()->creditsRemaining; // 100.0
     * ```
     *
     * @return Balance The balance.
     */
    public function balance(): Balance
    {
        return $this->balance;
    }

    /**
     * Record a queued decision instead of dispatching a job (DecisionQueue).
     *
     * Example:
     * ```php
     * Jev::state('t')->noul('q', 'Q?')->queue();
     * Jev::assertQueued();
     * ```
     *
     * @param  DecisionRequest  $request  The request.
     * @param  CorrelationId  $correlationId  The pending correlation id.
     * @return void Nothing.
     */
    public function push(DecisionRequest $request, CorrelationId $correlationId): void
    {
        $this->queued[] = ['request' => $request, 'correlationId' => $correlationId];
    }

    /**
     * Requests of every decision attempt, in order (retried attempts included).
     *
     * Example:
     * ```php
     * $fake->recorded()[0]->state->value;
     * ```
     *
     * @return list<DecisionRequest> The requests.
     */
    public function recorded(): array
    {
        return array_column($this->decisions, 'request');
    }

    /**
     * Assert that a decision was evaluated, optionally matching a condition.
     *
     * Example:
     * ```php
     * Jev::assertEvaluated(fn (DecisionRequest $r) => $r->context->get('ticket_id') === 42);
     * ```
     *
     * @param  Closure(DecisionRequest): bool|null  $matching  Condition on the request.
     * @return void Nothing.
     */
    public function assertEvaluated(?Closure $matching = null): void
    {
        PHPUnit::assertNotEmpty($this->matching($matching), 'No matching Jev decision was evaluated.');
    }

    /**
     * Assert that no matching decision was evaluated.
     *
     * Example:
     * ```php
     * Jev::assertNotEvaluated(fn (DecisionRequest $r) => $r->isJudgeCall());
     * ```
     *
     * @param  Closure(DecisionRequest): bool|null  $matching  Condition on the request.
     * @return void Nothing.
     */
    public function assertNotEvaluated(?Closure $matching = null): void
    {
        PHPUnit::assertEmpty($this->matching($matching), 'An unexpected Jev decision was evaluated.');
    }

    /**
     * Assert that nothing was sent to Jev at all.
     *
     * Example:
     * ```php
     * Jev::assertNothingEvaluated();
     * ```
     *
     * @return void Nothing.
     */
    public function assertNothingEvaluated(): void
    {
        PHPUnit::assertSame([], [...$this->decisions, ...$this->webContexts], 'Jev calls were made unexpectedly.');
    }

    /**
     * Assert how many decision attempts were made.
     *
     * Example:
     * ```php
     * Jev::assertEvaluatedTimes(2);
     * ```
     *
     * @param  int  $times  Expected number of attempts.
     * @return void Nothing.
     */
    public function assertEvaluatedTimes(int $times): void
    {
        PHPUnit::assertCount($times, $this->decisions, "Expected {$times} Jev decision attempt(s), got ".count($this->decisions).'.');
    }

    /**
     * Assert that a saved judge was used, optionally pinned to a revision.
     *
     * Example:
     * ```php
     * Jev::assertJudgeUsed('judge_123', revision: 3);
     * ```
     *
     * @param  string  $judgeId  The judge id.
     * @param  int|null  $revision  The pinned revision, or null for any.
     * @return void Nothing.
     */
    public function assertJudgeUsed(string $judgeId, ?int $revision = null): void
    {
        $this->assertEvaluated(fn (DecisionRequest $request): bool => $request->judge?->id === $judgeId
            && ($revision === null || $request->judge->revision === $revision));
    }

    /**
     * Assert that the input tokens of all successful fake decisions stay under a limit.
     *
     * Example:
     * ```php
     * Jev::fake()->withUsage(input: 120);
     * // ...
     * Jev::assertTokensUsedLessThan(500);
     * ```
     *
     * @param  int  $limit  Exclusive upper bound.
     * @return void Nothing.
     */
    public function assertTokensUsedLessThan(int $limit): void
    {
        $used = 0;

        foreach ($this->decisions as $decision) {
            $used += $decision['result']?->usage->inputTokens ?? 0;
        }

        PHPUnit::assertLessThan($limit, $used, "Jev used {$used} input tokens, expected fewer than {$limit}.");
    }

    /**
     * Assert that a web-context question was resolved, optionally matching a condition.
     *
     * Example:
     * ```php
     * Jev::assertWebContextResolved(fn (WebContextRequest $r) => str_contains($r->question, 'GPT-6'));
     * ```
     *
     * @param  Closure(WebContextRequest): bool|null  $matching  Condition on the request.
     * @return void Nothing.
     */
    public function assertWebContextResolved(?Closure $matching = null): void
    {
        $found = array_filter($this->webContexts, fn (WebContextRequest $request): bool => $matching === null || $matching($request));

        PHPUnit::assertNotEmpty($found, 'No matching Jev web-context question was resolved.');
    }

    /**
     * Assert that a decision was queued, optionally matching a condition.
     *
     * Example:
     * ```php
     * Jev::assertQueued(fn (DecisionRequest $r) => $r->context->get('ticket_id') === 42);
     * ```
     *
     * @param  Closure(DecisionRequest): bool|null  $matching  Condition on the request.
     * @return void Nothing.
     */
    public function assertQueued(?Closure $matching = null): void
    {
        $found = array_filter($this->queued, fn (array $queued): bool => $matching === null || $matching($queued['request']));

        PHPUnit::assertNotEmpty($found, 'No matching Jev decision was queued.');
    }

    /**
     * Requests of attempts matching a condition.
     *
     * Example:
     * ```php
     * $this->matching(fn (DecisionRequest $r) => $r->isJudgeCall());
     * ```
     *
     * @param  Closure(DecisionRequest): bool|null  $matching  Condition, or null for all.
     * @return list<DecisionRequest> Matching requests.
     */
    private function matching(?Closure $matching): array
    {
        return array_values(array_filter($this->recorded(), fn (DecisionRequest $request): bool => $matching === null || $matching($request)));
    }

    /**
     * Build the result of a request from configured answers.
     *
     * Example:
     * ```php
     * $this->result($request, $id, ['department' => 'billing']);
     * ```
     *
     * @param  DecisionRequest  $request  The request.
     * @param  CorrelationId  $correlationId  The correlation id.
     * @param  array<string, mixed>  $answers  Question id => configured answer.
     * @return DecisionResult The result.
     *
     * @throws InvalidValue When a configured answer does not fit its question.
     */
    private function result(DecisionRequest $request, CorrelationId $correlationId, array $answers): DecisionResult
    {
        $mapped = [];

        foreach ($request->questions ?? [] as $id => $question) {
            $mapped[] = self::answerFor($question, $answers[$id] ?? null);
        }

        if ($request->judge !== null) {
            foreach ($answers as $id => $value) {
                $mapped[] = $value instanceof Answer ? $value : self::answerFor(new NoulQuestion($id, 'Judge rule'), $value);
            }
        }

        return new DecisionResult(
            new AnswerSet(...$mapped),
            $this->usage,
            $this->billing,
            new RunMetadata(
                $correlationId,
                $request->model ?? 'jev-latest',
                0,
                responseId: 'fake_'.$correlationId->value,
                judgeId: $request->judge?->id,
                judgeRevision: $request->judge?->revision,
                connection: 'fake',
            ),
        );
    }

    /**
     * Turn a configured value into an answer of the question's type.
     *
     * Example:
     * ```php
     * self::answerFor(new NoulQuestion('q', 'Q?'), true); // NoulAnswer('q', 1.0)
     * ```
     *
     * @param  Question  $question  The question.
     * @param  mixed  $value  Configured answer, or null for the default.
     * @return Answer The answer.
     *
     * @throws InvalidValue When the value does not fit the question.
     */
    private static function answerFor(Question $question, mixed $value): Answer
    {
        if ($value instanceof Answer) {
            return $value;
        }

        return match (true) {
            $question instanceof ChoiceQuestion => new ChoiceAnswer(
                $question->id,
                is_string($value) ? $value : $question->options()[0],
                1.0,
            ),
            $question instanceof ScoreQuestion => new ScoreAnswer(
                $question->id,
                is_int($value) || is_float($value) ? (float) $value : 0.0,
                1.0,
                $question->levels,
            ),
            default => new NoulAnswer($question->id, match (true) {
                is_bool($value) => $value ? 1.0 : 0.0,
                is_int($value), is_float($value) => (float) $value,
                default => 0.5,
            }),
        };
    }
}
