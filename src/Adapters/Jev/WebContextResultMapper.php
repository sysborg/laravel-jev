<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use DateTimeImmutable;
use Exception;
use Illuminate\Http\Client\Response;
use Sysborg\LaravelJevai\Domain\Answer\ChoiceAnswer;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Exceptions\UnexpectedResponse;
use Sysborg\LaravelJevai\Domain\Run\CorrelationId;
use Sysborg\LaravelJevai\Domain\Run\RunMetadata;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextLatency;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextResult;
use Sysborg\LaravelJevai\Domain\WebContext\WebContextUsage;
use Sysborg\LaravelJevai\Domain\WebContext\WebSource;

/**
 * Maps a successful `POST /v1/web-context` response into a {@see WebContextResult}.
 */
final class WebContextResultMapper
{
    /**
     * Map the response.
     *
     * Example:
     * ```php
     * $result = WebContextResultMapper::map($response, $correlationId, $connection, latencyMs: 980);
     * ```
     *
     * @param  Response  $response  A 2xx response.
     * @param  CorrelationId  $correlationId  Id to stamp on the metadata.
     * @param  JevConnection  $connection  Connection used, for its name and default model.
     * @param  int  $latencyMs  Duration of this single attempt.
     * @return WebContextResult Decision, evidence, usage, latency, billing and metadata.
     *
     * @throws UnexpectedResponse When the body or a header cannot be understood.
     */
    public static function map(
        Response $response,
        CorrelationId $correlationId,
        JevConnection $connection,
        int $latencyMs,
    ): WebContextResult {
        $body = ResponseReader::fromResponse($response);

        try {
            $usage = $body->optionalObject('usage');
            $latency = $body->optionalObject('latency');

            return new WebContextResult(
                decision: $body->string('decision'),
                confidence: $body->float('confidence'),
                withWeb: self::answer('with_web', $body->optionalObject('with_web')),
                withoutWeb: self::answer('without_web', $body->optionalObject('without_web')),
                sources: self::sources($body),
                sourceWeights: self::weights($body),
                usage: new WebContextUsage(
                    $usage?->optionalInt('input_tokens') ?? 0,
                    $usage?->optionalInt('output_tokens') ?? 0,
                    $usage?->optionalInt('jev_calls') ?? 0,
                    $usage?->optionalBool('web_search') ?? false,
                ),
                latency: new WebContextLatency(
                    $latency?->optionalInt('search_ms') ?? 0,
                    $latency?->optionalInt('jev_ms') ?? 0,
                ),
                meta: new RunMetadata(
                    correlationId: $correlationId,
                    model: $body->optionalString('model') ?? $connection->model,
                    latencyMs: $latencyMs,
                    responseId: $body->optionalString('id'),
                    runId: BillingMapper::header($response, 'X-Jev-Run-Id'),
                    connection: $connection->name,
                ),
                billing: BillingMapper::map($response, $body),
                raw: array_combine(array_map(strval(...), array_keys($body->toArray())), $body->toArray()),
            );
        } catch (InvalidValue $e) {
            throw new UnexpectedResponse('Jev returned a value outside its documented range: '.$e->getMessage(), $response->status(), $e);
        }
    }

    /**
     * Map the `answer` of `with_web` or `without_web`.
     *
     * Example:
     * ```php
     * self::answer('with_web', $body->optionalObject('with_web')); // ChoiceAnswer('with_web', 'yes', ...)
     * ```
     *
     * @param  string  $id  Id given to the answer (`with_web` or `without_web`).
     * @param  ResponseReader|null  $branch  The branch object, or null when absent.
     * @return ChoiceAnswer|null The answer, or null when the branch has none.
     *
     * @throws UnexpectedResponse When the answer has the wrong shape.
     * @throws InvalidValue When a probability is outside 0..1.
     */
    private static function answer(string $id, ?ResponseReader $branch): ?ChoiceAnswer
    {
        $answer = $branch?->optionalObject('answer');

        if ($answer === null) {
            return null;
        }

        return new ChoiceAnswer(
            $id,
            $answer->string('choice'),
            $answer->optionalFloat('confidence'),
            $answer->optionalNumberMap('probabilities'),
        );
    }

    /**
     * Map the `sources` list.
     *
     * Example:
     * ```php
     * self::sources($body); // [WebSource('...', 'https://...', DateTimeImmutable, ['...'])]
     * ```
     *
     * @param  ResponseReader  $body  The decoded body.
     * @return list<WebSource> The sources; empty when absent.
     *
     * @throws UnexpectedResponse When a source has the wrong shape.
     * @throws InvalidValue When a source URL is blank.
     */
    private static function sources(ResponseReader $body): array
    {
        $sources = [];

        foreach ($body->optionalList('sources') ?? [] as $index => $item) {
            if (! is_array($item)) {
                throw $body->invalid("sources.{$index}", 'an object');
            }

            $source = ResponseReader::of($item, "sources.{$index}", $body->status());
            $highlights = array_values(array_filter($source->optionalList('highlights') ?? [], is_string(...)));

            $sources[] = new WebSource(
                $source->optionalString('title') ?? '',
                $source->string('url'),
                self::date($source->optionalString('publishedDate')),
                $highlights,
            );
        }

        return $sources;
    }

    /**
     * Map `source_weights`, given either as a list or as an object of numbers.
     *
     * Example:
     * ```php
     * self::weights($body); // [0 => 0.7, 1 => 0.3] or null
     * ```
     *
     * @param  ResponseReader  $body  The decoded body.
     * @return array<array-key, float>|null The weights, or null when not returned.
     *
     * @throws UnexpectedResponse When a weight is not a number.
     */
    private static function weights(ResponseReader $body): ?array
    {
        $raw = $body->toArray()['source_weights'] ?? null;

        if ($raw === null) {
            return null;
        }

        if (! is_array($raw)) {
            throw $body->invalid('source_weights', 'a list or an object of numbers');
        }

        $weights = [];

        foreach ($raw as $key => $weight) {
            if (! is_int($weight) && ! is_float($weight)) {
                throw $body->invalid("source_weights.{$key}", 'a number');
            }

            $weights[$key] = (float) $weight;
        }

        return $weights;
    }

    /**
     * Parse a publication date leniently; unreadable dates are dropped.
     *
     * Example:
     * ```php
     * self::date('2026-09-10T00:00:00.000Z'); // DateTimeImmutable
     * self::date('not a date');               // null
     * ```
     *
     * @param  string|null  $value  The raw date.
     * @return DateTimeImmutable|null The date, or null when absent or unreadable.
     */
    private static function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
