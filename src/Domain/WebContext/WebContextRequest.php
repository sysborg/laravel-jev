<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\WebContext;

use Sysborg\LaravelJevai\Domain\Decision\Context;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * A yes/no question answered with and without web evidence.
 *
 * @api
 */
final readonly class WebContextRequest
{
    public const int DEFAULT_RESULTS = 6;

    public const int MAX_RESULTS = 10;

    public const int MAX_SOURCES = 10;

    /**
     * Create the request. Use {@see ask()} and the `with*()` methods instead.
     *
     * Example:
     * ```php
     * WebContextRequest::ask('Has OpenAI released GPT-6?'); // the constructor is private
     * ```
     *
     * @param  string  $question  The yes/no question.
     * @param  string|null  $query  Search query; Jev derives one from the question when null.
     * @param  array{yes: string, no: string}|null  $criteria  What "yes" and "no" mean.
     * @param  int  $numResults  Search results to use, 1–10.
     * @param  bool  $attribution  Whether to return per-source weights (bills extra input tokens).
     * @param  list<WebSource>  $sources  Own evidence (at most 10); when set, no search runs.
     * @param  Context  $context  Local data attached to events, never sent to Jev.
     *
     * @throws InvalidValue When the question, query or a criterion is blank, the result count is
     *                      outside 1–10, or more than 10 sources are given.
     */
    private function __construct(
        public string $question,
        public ?string $query,
        public ?array $criteria,
        public int $numResults,
        public bool $attribution,
        public array $sources,
        public Context $context,
    ) {
        Guard::notBlank($question, 'web context question');

        if ($query !== null) {
            Guard::notBlank($query, 'web context query');
        }

        if ($criteria !== null) {
            Guard::notBlank($criteria['yes'], 'web context "yes" criterion');
            Guard::notBlank($criteria['no'], 'web context "no" criterion');
        }

        Guard::between($numResults, 1, self::MAX_RESULTS, 'web context result count');

        if (count($sources) > self::MAX_SOURCES) {
            throw InvalidValue::because('web context sources', 'must have at most '.self::MAX_SOURCES.' entries');
        }
    }

    /**
     * Start a request for a yes/no question with the documented defaults.
     *
     * Example:
     * ```php
     * WebContextRequest::ask('Has OpenAI released GPT-6?')
     *     ->withQuery('OpenAI releases GPT-6 announcement')
     *     ->withNumResults(6);
     * ```
     *
     * @param  string  $question  The yes/no question.
     * @return self The request with 6 results, no attribution and no own sources.
     *
     * @throws InvalidValue When the question is blank.
     */
    public static function ask(string $question): self
    {
        return new self($question, null, null, self::DEFAULT_RESULTS, false, [], Context::empty());
    }

    /**
     * Return a copy with an explicit search query.
     *
     * Example:
     * ```php
     * $request = $request->withQuery('OpenAI releases GPT-6 announcement');
     * ```
     *
     * @param  string|null  $query  Search query, or null to let Jev derive it.
     * @return self A new request; the original is unchanged.
     *
     * @throws InvalidValue When the query is blank.
     */
    public function withQuery(?string $query): self
    {
        return new self($this->question, $query, $this->criteria, $this->numResults, $this->attribution, $this->sources, $this->context);
    }

    /**
     * Return a copy that defines what "yes" and "no" mean.
     *
     * Example:
     * ```php
     * $request = $request->withCriteria(
     *     yes: 'OpenAI has publicly released a model named GPT-6',
     *     no: 'No model named GPT-6 has been released',
     * );
     * ```
     *
     * @param  string  $yes  When to answer "yes".
     * @param  string  $no  When to answer "no".
     * @return self A new request; the original is unchanged.
     *
     * @throws InvalidValue When a criterion is blank.
     */
    public function withCriteria(string $yes, string $no): self
    {
        return new self($this->question, $this->query, ['yes' => $yes, 'no' => $no], $this->numResults, $this->attribution, $this->sources, $this->context);
    }

    /**
     * Return a copy using another number of search results.
     *
     * Example:
     * ```php
     * $request = $request->withNumResults(3);
     * ```
     *
     * @param  int  $numResults  Search results to use, 1–10.
     * @return self A new request; the original is unchanged.
     *
     * @throws InvalidValue When the count is outside 1–10.
     */
    public function withNumResults(int $numResults): self
    {
        return new self($this->question, $this->query, $this->criteria, $numResults, $this->attribution, $this->sources, $this->context);
    }

    /**
     * Return a copy that asks Jev for per-source weights. Bills extra input tokens.
     *
     * Example:
     * ```php
     * $request = $request->withAttribution();
     * ```
     *
     * @param  bool  $attribution  Whether to request source weights.
     * @return self A new request; the original is unchanged.
     */
    public function withAttribution(bool $attribution = true): self
    {
        return new self($this->question, $this->query, $this->criteria, $this->numResults, $attribution, $this->sources, $this->context);
    }

    /**
     * Return a copy that supplies its own evidence: no web search runs and no search fee applies.
     *
     * Example:
     * ```php
     * $request = $request->withSources(
     *     new WebSource('Release notes', 'https://example.com/notes', null, ['GPT-6 is live']),
     * );
     * ```
     *
     * @param  WebSource  ...$sources  Up to 10 sources; none removes own sources.
     * @return self A new request; the original is unchanged.
     *
     * @throws InvalidValue When more than 10 sources are given.
     */
    public function withSources(WebSource ...$sources): self
    {
        return new self($this->question, $this->query, $this->criteria, $this->numResults, $this->attribution, array_values($sources), $this->context);
    }

    /**
     * Return a copy with another local context.
     *
     * Example:
     * ```php
     * $request = $request->withContext(Context::of(['claim_id' => 7]));
     * ```
     *
     * @param  Context  $context  Local data attached to events, never sent to Jev.
     * @return self A new request; the original is unchanged.
     */
    public function withContext(Context $context): self
    {
        return new self($this->question, $this->query, $this->criteria, $this->numResults, $this->attribution, $this->sources, $context);
    }

    /**
     * Whether the request supplies its own evidence instead of searching the web.
     *
     * Example:
     * ```php
     * $request->usesOwnSources(); // false
     * ```
     *
     * @return bool True when sources were given.
     */
    public function usesOwnSources(): bool
    {
        return $this->sources !== [];
    }
}
