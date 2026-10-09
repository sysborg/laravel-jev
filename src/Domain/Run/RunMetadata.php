<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Run;

use Sysborg\LaravelJevai\Domain\Exceptions\InvalidValue;
use Sysborg\LaravelJevai\Domain\Support\Guard;

/**
 * Who answered a call, how long it took and how to find it again.
 *
 * @api
 */
final readonly class RunMetadata
{
    /**
     * Create the metadata.
     *
     * Example:
     * ```php
     * new RunMetadata(
     *     correlationId: new CorrelationId('c-1'),
     *     model: 'jev-latest',
     *     latencyMs: 412,
     *     attempts: 2,
     *     runId: $response->header('X-Jev-Run-Id'),
     * );
     * ```
     *
     * @param  CorrelationId  $correlationId  Id linking the call to events, logs and spans.
     * @param  string  $model  Model that answered, as reported by Jev.
     * @param  int  $latencyMs  Wall time of the whole call in milliseconds, retries included.
     * @param  int  $attempts  Number of HTTP attempts, at least 1.
     * @param  string|null  $responseId  Response `id` field.
     * @param  string|null  $runId  `X-Jev-Run-Id` header, when Jev sends it.
     * @param  string|null  $provider  Response `provider` field.
     * @param  string|null  $judgeId  `X-Jev-Judge-Id` header, for judge calls.
     * @param  int|null  $judgeRevision  `X-Jev-Judge-Revision` header, for judge calls.
     * @param  string|null  $connection  Package connection name used for the call.
     *
     * @throws InvalidValue When the model is blank, the latency is negative or attempts is lower than 1.
     */
    public function __construct(
        public CorrelationId $correlationId,
        public string $model,
        public int $latencyMs,
        public int $attempts = 1,
        public ?string $responseId = null,
        public ?string $runId = null,
        public ?string $provider = null,
        public ?string $judgeId = null,
        public ?int $judgeRevision = null,
        public ?string $connection = null,
    ) {
        Guard::notBlank($model, 'run model');
        Guard::nonNegative($latencyMs, 'run latency');

        if ($attempts < 1) {
            throw InvalidValue::because('run attempts', 'must be at least 1');
        }
    }

    /**
     * Whether more than one HTTP attempt was needed.
     *
     * Example:
     * ```php
     * if ($result->meta->wasRetried()) {
     *     Log::info("Jev call succeeded after {$result->meta->attempts} attempts.");
     * }
     * ```
     *
     * @return bool True when attempts is greater than 1.
     */
    public function wasRetried(): bool
    {
        return $this->attempts > 1;
    }

    /**
     * Return a copy with the timing of the whole call, retries included.
     *
     * Gateways report a single attempt; the application stamps the final numbers.
     *
     * Example:
     * ```php
     * $meta = $result->meta->withTiming(latencyMs: 1_240, attempts: 3);
     * ```
     *
     * @param  int  $latencyMs  Wall time of the whole call in milliseconds.
     * @param  int  $attempts  Number of HTTP attempts, at least 1.
     * @return self A new metadata; the original is unchanged.
     *
     * @throws InvalidValue When the latency is negative or attempts is lower than 1.
     */
    public function withTiming(int $latencyMs, int $attempts): self
    {
        return new self(
            $this->correlationId, $this->model, $latencyMs, $attempts, $this->responseId,
            $this->runId, $this->provider, $this->judgeId, $this->judgeRevision, $this->connection,
        );
    }

    /**
     * Return a copy tagged with the package connection that made the call.
     *
     * Example:
     * ```php
     * $meta = $meta->withConnection('tenant-a');
     * ```
     *
     * @param  string|null  $connection  Connection name, or null for the default one.
     * @return self A new metadata; the original is unchanged.
     */
    public function withConnection(?string $connection): self
    {
        return new self(
            $this->correlationId, $this->model, $this->latencyMs, $this->attempts, $this->responseId,
            $this->runId, $this->provider, $this->judgeId, $this->judgeRevision, $connection,
        );
    }
}
