<?php

declare(strict_types=1);

namespace Sysborg\LaravelJevai\Domain\Exceptions;

use Throwable;

/**
 * Marker implemented by every exception thrown by this package,
 * so applications can catch them all with a single type.
 *
 * @api
 */
interface JevThrowable extends Throwable {}
