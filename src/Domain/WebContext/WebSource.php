<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\WebContext;

use DateTimeImmutable;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * A piece of evidence: returned by Jev's web search, or supplied by the app.
 */
final readonly class WebSource
{
    /** @var list<string> */
    public array $highlights;

    /**
     * Create the source.
     *
     * Example:
     * ```php
     * new WebSource(
     *     'OpenAI announces GPT-6',
     *     'https://example.com/gpt-6',
     *     new DateTimeImmutable('2026-09-10'),
     *     ['OpenAI released GPT-6 today...'],
     * );
     * ```
     *
     * @param  string  $title  Page title; may be empty when the search returned none.
     * @param  string  $url  Page URL.
     * @param  DateTimeImmutable|null  $publishedAt  Publication date, when known.
     * @param  list<string>  $highlights  Relevant excerpts of the page.
     *
     * @throws InvalidValue When the URL is blank or the highlights are not a list.
     */
    public function __construct(
        public string $title,
        public string $url,
        public ?DateTimeImmutable $publishedAt = null,
        array $highlights = [],
    ) {
        Guard::notBlank($url, 'web source url');

        if (! array_is_list($highlights)) {
            throw InvalidValue::because('web source highlights', 'must be a list');
        }

        $this->highlights = $highlights;
    }
}
