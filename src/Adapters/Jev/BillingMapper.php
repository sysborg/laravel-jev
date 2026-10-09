<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Adapters\Jev;

use Illuminate\Http\Client\Response;
use Sysborg\LaravelJevai\Domain\Exceptions\UnexpectedResponse;
use Sysborg\LaravelJevai\Domain\Usage\Billing;
use Sysborg\LaravelJevai\Domain\Usage\BillingMode;

/**
 * Reads billing from the response body (`billing.*`), falling back to the `X-Jev-*` headers.
 */
final class BillingMapper
{
    /**
     * Build the billing of a successful call.
     *
     * Example:
     * ```php
     * $billing = BillingMapper::map($response, ResponseReader::fromResponse($response));
     * $billing->mode; // BillingMode::Tokens
     * ```
     *
     * @param  Response  $response  The response, for the `X-Jev-*` headers.
     * @param  ResponseReader  $body  The decoded body.
     * @return Billing Mode, charges and remaining tokens.
     *
     * @throws UnexpectedResponse When a billing field or header has the wrong type.
     */
    public static function map(Response $response, ResponseReader $body): Billing
    {
        $billing = $body->optionalObject('billing');

        return new Billing(
            mode: BillingMode::fromValue($billing?->optionalString('mode') ?? self::header($response, 'X-Jev-Billing')),
            inputTokensCharged: $billing?->optionalInt('input_tokens_charged')
                ?? self::intHeader($response, 'X-Jev-Paid-Input-Tokens-Used')
                ?? 0,
            creditsCharged: $billing?->optionalFloat('credits_charged')
                ?? self::floatHeader($response, 'X-Jev-Credits-Charged')
                ?? 0.0,
            tokensRemaining: self::intHeader($response, 'X-Jev-Tokens-Remaining'),
        );
    }

    /**
     * Read a header as a trimmed string.
     *
     * Example:
     * ```php
     * self::header($response, 'X-Jev-Run-Id'); // 'run_123' or null
     * ```
     *
     * @param  Response  $response  The response.
     * @param  string  $name  Header name.
     * @return string|null The value, or null when absent or empty.
     */
    public static function header(Response $response, string $name): ?string
    {
        $value = trim($response->header($name));

        return $value === '' ? null : $value;
    }

    /**
     * Read a header as a non-negative integer.
     *
     * Example:
     * ```php
     * self::intHeader($response, 'X-Jev-Tokens-Remaining'); // 9880
     * ```
     *
     * @param  Response  $response  The response.
     * @param  string  $name  Header name.
     * @return int|null The value, or null when absent.
     *
     * @throws UnexpectedResponse When the header is present but not an integer.
     */
    public static function intHeader(Response $response, string $name): ?int
    {
        $value = self::header($response, $name);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new UnexpectedResponse("Jev response header [{$name}] must be an integer.", $response->status());
        }

        return (int) $value;
    }

    /**
     * Read a header as a non-negative number.
     *
     * Example:
     * ```php
     * self::floatHeader($response, 'X-Jev-Credits-Charged'); // 1.0
     * ```
     *
     * @param  Response  $response  The response.
     * @param  string  $name  Header name.
     * @return float|null The value, or null when absent.
     *
     * @throws UnexpectedResponse When the header is present but not a number.
     */
    public static function floatHeader(Response $response, string $name): ?float
    {
        $value = self::header($response, $name);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            throw new UnexpectedResponse("Jev response header [{$name}] must be a number.", $response->status());
        }

        return (float) $value;
    }
}
