<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Usage;

/**
 * How usage summaries are grouped.
 *
 * @api
 */
enum UsageGrouping: string
{
    /** A single total for the whole period. */
    case None = 'none';

    case Model = 'model';

    case Connection = 'connection';

    case Operation = 'operation';

    case User = 'user';

    case Session = 'session';

    /** One group per calendar day (UTC), keyed `Y-m-d`. */
    case Day = 'day';
}
