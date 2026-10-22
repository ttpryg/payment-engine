<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\Exception;

use InvalidArgumentException;

final class InvalidPaymentArgumentException extends InvalidArgumentException implements ExceptionInterface {}
