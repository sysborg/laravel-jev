<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Usage;

/**
 * Which Jev operation a usage record refers to.
 *
 * @api
 */
enum RunOperation: string
{
    /** Decision with inline questions. */
    case Decision = 'decision';

    /** Decision answered by a saved judge. */
    case Judge = 'judge';

    /** Web-context yes/no decision. */
    case WebContext = 'web_context';
}
