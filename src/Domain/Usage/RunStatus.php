<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Usage;

/**
 * Final outcome of a call, after retries.
 */
enum RunStatus: string
{
    case Succeeded = 'succeeded';

    case Failed = 'failed';
}
