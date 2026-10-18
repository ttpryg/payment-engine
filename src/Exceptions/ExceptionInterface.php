<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Exceptions;

use Throwable;

/**
 * Root marker interface implemented by every exception thrown by this library.
 *
 * Consumers may catch this single interface to handle all library-specific
 * errors, e.g. `catch (ExceptionInterface $e)`.
 */
interface ExceptionInterface extends Throwable {}
