<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use Throwable;

/**
 * Jev answered with a body this package cannot understand.
 *
 * A successful status means the call was likely executed and billed.
 */
final class UnexpectedResponse extends JevException
{
    /**
     * Create the exception.
     *
     * Example:
     * ```php
     * throw new UnexpectedResponse('Missing "answers" in the response.', 200);
     * ```
     *
     * @param  string  $message  Human readable description of what could not be parsed.
     * @param  int|null  $httpStatus  HTTP status of the response, when known.
     * @param  Throwable|null  $previous  Underlying exception, e.g. a JSON decoding error.
     */
    public function __construct(
        string $message = 'Jev returned a response that could not be understood.',
        ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, null, $previous);
    }

    /**
     * A 2xx (or unknown) status means Jev likely ran and billed the call.
     *
     * Example:
     * ```php
     * (new UnexpectedResponse(httpStatus: 200))->mayHaveBeenBilled(); // true
     * (new UnexpectedResponse(httpStatus: 500))->mayHaveBeenBilled(); // false
     * ```
     *
     * @return bool True when the status is 2xx or unknown.
     */
    public function mayHaveBeenBilled(): bool
    {
        return $this->httpStatus === null || ($this->httpStatus >= 200 && $this->httpStatus < 300);
    }
}
