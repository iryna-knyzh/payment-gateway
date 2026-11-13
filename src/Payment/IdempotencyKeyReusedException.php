<?php

declare(strict_types=1);

namespace App\Payment;

/** The same Idempotency-Key was sent with a different request body. */
final class IdempotencyKeyReusedException extends \RuntimeException
{
}
