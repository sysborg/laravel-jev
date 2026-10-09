<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\WebContext;

use Sysborg\LaravelJevai\Domain\Answer\ChoiceAnswer;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Run\RunMetadata;
use Sysborg\LaravelJevai\Domain\Support\Guard;
use Sysborg\LaravelJevai\Domain\Usage\Billing;

/**
 * Everything Jev returned for a web-context request.
 */
final readonly class WebContextResult
{
    public float $confidence;

    /** @var list<WebSource> */
    public array $sources;

    /**
     * Create the result.
     *
     * Example:
     * ```php
     * new WebContextResult(
     *     decision: 'yes',
     *     confidence: 0.88,
     *     withWeb: new ChoiceAnswer('with_web', 'yes', 0.88),
     *     withoutWeb: new ChoiceAnswer('without_web', 'no'),
     *     sources: [$source],
     *     sourceWeights: null,
     *     usage: new WebContextUsage(2140, 12, 2, true),
     *     latency: new WebContextLatency(560, 410),
     *     meta: $meta,
     *     billing: $billing,
     * );
     * ```
     *
     * @param  string  $decision  Final decision, usually "yes" or "no".
     * @param  float  $confidence  Confidence in the decision, from 0 to 1.
     * @param  ChoiceAnswer|null  $withWeb  Answer given with the evidence.
     * @param  ChoiceAnswer|null  $withoutWeb  Answer given without the evidence.
     * @param  list<WebSource>  $sources  Evidence used.
     * @param  array<array-key, float>|null  $sourceWeights  Per-source weights, only with attribution.
     * @param  WebContextUsage  $usage  Tokens and calls consumed.
     * @param  WebContextLatency  $latency  Server-side time split.
     * @param  RunMetadata  $meta  Correlation id, model, latency, attempts and Jev ids.
     * @param  Billing  $billing  What was charged and what is left.
     * @param  array<string, mixed>|null  $raw  Decoded response body, kept only when configured.
     *
     * @throws InvalidValue When the decision is blank, the confidence is not a probability or the
     *                      sources are not a list.
     */
    public function __construct(
        public string $decision,
        float $confidence,
        public ?ChoiceAnswer $withWeb,
        public ?ChoiceAnswer $withoutWeb,
        array $sources,
        public ?array $sourceWeights,
        public WebContextUsage $usage,
        public WebContextLatency $latency,
        public RunMetadata $meta,
        public Billing $billing,
        public ?array $raw = null,
    ) {
        Guard::notBlank($decision, 'web context decision');

        if (! array_is_list($sources)) {
            throw InvalidValue::because('web context sources', 'must be a list');
        }

        $this->confidence = Guard::probability($confidence, 'web context confidence');
        $this->sources = $sources;
    }

    /**
     * Whether the final decision is "yes".
     *
     * Example:
     * ```php
     * if ($result->isYes() && $result->confidence > 0.8) {
     *     $claim->markVerified();
     * }
     * ```
     *
     * @return bool True when the decision is "yes".
     */
    public function isYes(): bool
    {
        return $this->decision === 'yes';
    }

    /**
     * Whether the web evidence flipped the answer the model gives without it.
     *
     * Example:
     * ```php
     * if ($result->evidenceChangedDecision()) {
     *     Log::info('Fresh evidence changed the answer.', ['sources' => count($result->sources)]);
     * }
     * ```
     *
     * @return bool True when both answers exist and differ.
     */
    public function evidenceChangedDecision(): bool
    {
        return $this->withWeb !== null
            && $this->withoutWeb !== null
            && $this->withWeb->choice !== $this->withoutWeb->choice;
    }

    /**
     * Return a copy without the raw response body, e.g. before storing or dispatching it.
     *
     * Example:
     * ```php
     * event(new WebContextResolved($result->withoutRaw()));
     * ```
     *
     * @return self A new result with `raw` set to null.
     */
    public function withoutRaw(): self
    {
        return new self(
            $this->decision, $this->confidence, $this->withWeb, $this->withoutWeb, $this->sources,
            $this->sourceWeights, $this->usage, $this->latency, $this->meta, $this->billing,
        );
    }

    /**
     * Return a copy with other run metadata, e.g. after retries were counted.
     *
     * Example:
     * ```php
     * $result = $result->withMeta($result->meta->withTiming($elapsedMs, $attempts));
     * ```
     *
     * @param  RunMetadata  $meta  The new metadata.
     * @return self A new result; the original is unchanged.
     */
    public function withMeta(RunMetadata $meta): self
    {
        return new self(
            $this->decision, $this->confidence, $this->withWeb, $this->withoutWeb, $this->sources,
            $this->sourceWeights, $this->usage, $this->latency, $meta, $this->billing, $this->raw,
        );
    }
}
