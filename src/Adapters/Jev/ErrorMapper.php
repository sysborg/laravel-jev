<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use Illuminate\Http\Client\Response;
use Sysborg\LaravelJevai\Domain\Decision\JudgeRef;
use Sysborg\LaravelJevai\Domain\Exceptions\ApiError;
use Sysborg\LaravelJevai\Domain\Exceptions\InsufficientCredits;
use Sysborg\LaravelJevai\Domain\Exceptions\InvalidRequest;
use Sysborg\LaravelJevai\Domain\Exceptions\JevException;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeNotFound;
use Sysborg\LaravelJevai\Domain\Exceptions\JudgeRevisionMismatch;
use Sysborg\LaravelJevai\Domain\Exceptions\RateLimited;
use Sysborg\LaravelJevai\Domain\Exceptions\Unauthorized;
use Sysborg\LaravelJevai\Domain\Exceptions\UpstreamFailure;

/**
 * Maps Jev error responses (`{ "error": { "code", "message" } }`) to domain exceptions.
 */
final class ErrorMapper
{
    private const int MAX_MESSAGE_LENGTH = 500;

    /**
     * Build the exception for an unsuccessful response.
     *
     * Example:
     * ```php
     * if (! $response->successful()) {
     *     throw ErrorMapper::fromResponse($response, $request->judge);
     * }
     * ```
     *
     * @param  Response  $response  A response with a 4xx or 5xx status.
     * @param  JudgeRef|null  $judge  The judge of the request, to name it on 404 and 409.
     * @return JevException The matching exception, ready to be thrown.
     */
    public static function fromResponse(Response $response, ?JudgeRef $judge = null): JevException
    {
        $status = $response->status();
        [$code, $message] = self::error($response);

        return match (true) {
            $status === 401 => new Unauthorized($message ?? 'Jev rejected the API key.', $code),
            $status === 402 => new InsufficientCredits($message ?? 'The Jev account has no tokens or credits left to cover this request.', $code),
            $status === 404 && $judge !== null => new JudgeNotFound($judge->id, $message, $code),
            $status === 409 && $judge !== null => new JudgeRevisionMismatch($judge->id, $judge->revision, $message, $code),
            $status === 400, $status === 422 => new InvalidRequest($message ?? 'Jev rejected the request as invalid.', $status, $code),
            $status === 429 => new RateLimited(self::retryAfter($response), $message ?? 'Jev rate limit exceeded.', $code),
            in_array($status, [502, 503, 504], true) => new UpstreamFailure($status, $message ?? 'Jev upstream failure.', $code),
            default => new ApiError($status, $message ?? "Jev returned HTTP {$status}.", $code),
        };
    }

    /**
     * Read `error.code` and `error.message`, tolerating any body shape.
     *
     * Example:
     * ```php
     * [$code, $message] = self::error($response); // ['invalid_key', 'API key is invalid']
     * ```
     *
     * @param  Response  $response  The error response.
     * @return array{0: string|null, 1: string|null} The code and the (truncated) message.
     */
    private static function error(Response $response): array
    {
        $body = json_decode($response->body(), true);
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];

        $code = is_string($error['code'] ?? null) && $error['code'] !== '' ? $error['code'] : null;
        $message = is_string($error['message'] ?? null) && trim($error['message']) !== ''
            ? mb_substr(trim($error['message']), 0, self::MAX_MESSAGE_LENGTH)
            : null;

        return [$code, $message];
    }

    /**
     * Parse `Retry-After`, given either as seconds or as an HTTP date.
     *
     * Example:
     * ```php
     * self::retryAfter($response); // 30
     * ```
     *
     * @param  Response  $response  The 429 response.
     * @return int|null Seconds to wait (never negative), or null when absent or unreadable.
     */
    private static function retryAfter(Response $response): ?int
    {
        $value = trim($response->header('Retry-After'));

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }
}
