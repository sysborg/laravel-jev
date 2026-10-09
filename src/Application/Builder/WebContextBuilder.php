<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Application\Builder;

use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Domain\WebContext\WebSource;
use Sysborg\LaravelJevai\Ports\Driving\Jev;

/**
 * Immutable fluent builder for web-context requests.
 *
 * ```php
 * $result = Jev::webContext('Has OpenAI released GPT-6?')
 *     ->query('OpenAI releases GPT-6 announcement')
 *     ->numResults(6)
 *     ->resolve();
 * ```
 */
final readonly class WebContextBuilder
{
    /**
     * Create the builder. Use {@see for()} or the `Jev` facade.
     *
     * Example:
     * ```php
     * WebContextBuilder::for($jev, 'Has GPT-6 been released?'); // the constructor is private
     * ```
     *
     * @param  Jev  $client  Resolves the built request.
     * @param  WebContextRequest  $request  The request built so far.
     */
    private function __construct(
        private Jev $client,
        private WebContextRequest $request,
    ) {}

    /**
     * Start a builder for a yes/no question.
     *
     * Example:
     * ```php
     * WebContextBuilder::for(app(Jev::class), 'Has GPT-6 been released?')->resolve();
     * ```
     *
     * @param  Jev  $client  Resolves the built request.
     * @param  string  $question  The yes/no question.
     * @return self The builder with the documented defaults.
     *
     * @throws InvalidValue When the question is blank.
     */
    public static function for(Jev $client, string $question): self
    {
        return new self($client, WebContextRequest::ask($question));
    }

    /**
     * Set the search query.
     *
     * Example:
     * ```php
     * $builder->query('OpenAI releases GPT-6 announcement');
     * ```
     *
     * @param  string|null  $query  The query, or null to let Jev derive it.
     * @return self A new builder.
     *
     * @throws InvalidValue When the query is blank.
     */
    public function query(?string $query): self
    {
        return new self($this->client, $this->request->withQuery($query));
    }

    /**
     * Define what "yes" and "no" mean.
     *
     * Example:
     * ```php
     * $builder->criteria(yes: 'A model named GPT-6 is public', no: 'No such model is public');
     * ```
     *
     * @param  string  $yes  When to answer "yes".
     * @param  string  $no  When to answer "no".
     * @return self A new builder.
     *
     * @throws InvalidValue When a criterion is blank.
     */
    public function criteria(string $yes, string $no): self
    {
        return new self($this->client, $this->request->withCriteria($yes, $no));
    }

    /**
     * Set how many search results to use.
     *
     * Example:
     * ```php
     * $builder->numResults(3);
     * ```
     *
     * @param  int  $numResults  1–10.
     * @return self A new builder.
     *
     * @throws InvalidValue When the count is outside 1–10.
     */
    public function numResults(int $numResults): self
    {
        return new self($this->client, $this->request->withNumResults($numResults));
    }

    /**
     * Ask for per-source weights (bills extra input tokens).
     *
     * Example:
     * ```php
     * $builder->attribution();
     * ```
     *
     * @param  bool  $attribution  Whether to request weights.
     * @return self A new builder.
     */
    public function attribution(bool $attribution = true): self
    {
        return new self($this->client, $this->request->withAttribution($attribution));
    }

    /**
     * Supply your own evidence instead of a web search.
     *
     * Example:
     * ```php
     * $builder->sources(new WebSource('Release notes', 'https://example.com/notes'));
     * ```
     *
     * @param  WebSource  ...$sources  Up to 10 sources.
     * @return self A new builder.
     *
     * @throws InvalidValue When more than 10 sources are given.
     */
    public function sources(WebSource ...$sources): self
    {
        return new self($this->client, $this->request->withSources(...$sources));
    }

    /**
     * Add local data attached to every event of the call (never sent to Jev).
     *
     * Example:
     * ```php
     * $builder->context(['claim_id' => 7]);
     * ```
     *
     * @param  array<string, mixed>|string  $key  A key, or several values at once.
     * @param  mixed  $value  The value, when `$key` is a key.
     * @return self A new builder.
     *
     * @throws InvalidValue When a value holds objects, INF or NAN.
     */
    public function context(array|string $key, mixed $value = null): self
    {
        return new self($this->client, $this->request->withContext(
            $this->request->context->merge(is_array($key) ? $key : [$key => $value]),
        ));
    }

    /**
     * The request built so far.
     *
     * Example:
     * ```php
     * $request = Jev::webContext('Q?')->numResults(3)->toRequest();
     * ```
     *
     * @return WebContextRequest The request.
     */
    public function toRequest(): WebContextRequest
    {
        return $this->request;
    }

    /**
     * Resolve the request synchronously.
     *
     * Example:
     * ```php
     * $result = $builder->resolve();
     * $result->isYes(); // true
     * ```
     *
     * @return WebContextResult Decision, evidence, usage, latency, billing and metadata.
     *
     * @throws InvalidValue When the request breaks a Jev limit.
     * @throws JevException When the call fails after the configured retries.
     */
    public function resolve(): WebContextResult
    {
        return $this->client->resolveWebContext($this->request);
    }
}
