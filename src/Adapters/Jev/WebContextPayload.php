<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use DateTimeInterface;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextRequest;
use Sysborg\LaravelJevai\Domain\WebContext\WebSource;

/**
 * Builds the JSON body of `POST /v1/web-context`.
 */
final class WebContextPayload
{
    /**
     * Build the body.
     *
     * Example:
     * ```php
     * WebContextPayload::from(WebContextRequest::ask('Has GPT-6 been released?'));
     * // ['question' => 'Has GPT-6 been released?', 'num_results' => 6, 'attribution' => false]
     * ```
     *
     * @param  WebContextRequest  $request  The request to send.
     * @return array<string, mixed> The JSON body.
     */
    public static function from(WebContextRequest $request): array
    {
        $payload = ['question' => $request->question];

        if ($request->query !== null) {
            $payload['query'] = $request->query;
        }

        if ($request->criteria !== null) {
            $payload['criteria'] = $request->criteria;
        }

        $payload['num_results'] = $request->numResults;
        $payload['attribution'] = $request->attribution;

        if ($request->usesOwnSources()) {
            $payload['sources'] = array_map(self::source(...), $request->sources);
        }

        return $payload;
    }

    /**
     * Encode one own source, in the same shape Jev returns sources.
     *
     * Example:
     * ```php
     * self::source(new WebSource('Notes', 'https://example.com', null, ['GPT-6 is live']));
     * // ['title' => 'Notes', 'url' => 'https://example.com', 'highlights' => ['GPT-6 is live']]
     * ```
     *
     * @param  WebSource  $source  The source.
     * @return array<string, mixed> `title`, `url` and, when set, `publishedDate` and `highlights`.
     */
    private static function source(WebSource $source): array
    {
        $encoded = ['title' => $source->title, 'url' => $source->url];

        if ($source->publishedAt !== null) {
            $encoded['publishedDate'] = $source->publishedAt->format(DateTimeInterface::RFC3339_EXTENDED);
        }

        if ($source->highlights !== []) {
            $encoded['highlights'] = $source->highlights;
        }

        return $encoded;
    }
}
